<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardTransaction;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\CourtBooking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClubCardService
{
    /**
     * Выпустить карту клиенту.
     *
     * @param int|null    $balanceOverride остаток вручную (для счётчиков); null → номинал типа
     * @param string|null $expiresAt       дата окончания YYYY-MM-DD; null → берём из типа
     */
    public function issue(
        ClubClient $client,
        ClubCardType $type,
        ?int $balanceOverride = null,
        ?string $expiresAt = null
    ): ClubCard {
        // Остаток только для счётчиков (visits/trainer); скидочные — без баланса.
        $balance = null;
        $initial = null;
        if ($type->isCounter()) {
            $balance = $balanceOverride ?? (int) $type->nominal;
            $initial = $balance;
        }

        return ClubCard::create([
            'club_id' => $type->club_id,
            'club_card_type_id' => $type->id,
            'club_client_id' => $client->id,
            'code' => $this->nextCardCode($type),
            'balance' => $balance,
            'initial_balance' => $initial,
            'expires_at' => $this->resolveExpiry($type, $expiresAt),
            'status' => 'active',
        ]);
    }

    /**
     * Следующий номер карты серии типа: префикс + 6-значный номер (VIP000001).
     * Счётчик last_card_number персистентный (не переиспользуется после отвязки).
     */
    public function nextCardCode(ClubCardType $type): string
    {
        return DB::transaction(function () use ($type) {
            $locked = ClubCardType::where('id', $type->id)->lockForUpdate()->first();
            $prefix = $locked->code_prefix ?? '';
            do {
                $number = (int) $locked->last_card_number + 1;
                $locked->last_card_number = $number;
                $code = $prefix . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            } while (ClubCard::where('code', $code)->exists()); // защита от ручных коллизий
            $locked->save();
            $type->last_card_number = $number; // синхронизируем переданную модель
            return $code;
        });
    }

    /**
     * Списать часы карты-счётчика за завершённую бронь. Идемпотентно.
     *
     * Возвращает транзакцию списания или null (нечего/нельзя списывать).
     * Скидочные карты списания не требуют — просто помечаем бронь обработанной.
     */
    public function chargeBooking(CourtBooking $booking): ?ClubCardTransaction
    {
        if (!$booking->club_card_id) return null;
        if ($booking->card_charged_at) return null;        // уже обработана
        if ($booking->status !== 'confirmed') return null;  // отменённые не списываем

        $card = ClubCard::with('type')->find($booking->club_card_id);
        if (!$card) {
            $this->markCharged($booking);
            return null;
        }

        // Скидочная карта — списывать нечего.
        if (!$card->isCounter()) {
            $this->markCharged($booking);
            return null;
        }

        $hours = $this->bookingHours($booking);
        if ($hours <= 0) {
            $this->markCharged($booking);
            return null;
        }

        return DB::transaction(function () use ($booking, $card, $hours) {
            $locked = ClubCard::lockForUpdate()->find($card->id);
            // Перепроверяем идемпотентность под блокировкой брони.
            $freshBooking = CourtBooking::lockForUpdate()->find($booking->id);
            if (!$freshBooking || $freshBooking->card_charged_at) return null;

            $before = (int) $locked->balance;
            $charge = max(0, min($hours, $before)); // не уходим в минус
            $after = $before - $charge;
            $locked->balance = $after;
            $locked->save();

            $tx = ClubCardTransaction::create([
                'club_id' => $locked->club_id,
                'club_card_id' => $locked->id,
                'court_booking_id' => $freshBooking->id,
                'amount' => -$charge,
                'balance_after' => $after,
                'note' => 'Списание за бронь (' . $hours . ' ч)',
            ]);

            $freshBooking->forceFill(['card_charged_at' => now()])->save();
            return $tx;
        });
    }

    /**
     * Пометить бронь обработанной БЕЗ списания (ошибочная бронь / бесплатное
     * занятие). Идемпотентно. Пишет нулевую транзакцию для аудита.
     */
    public function skipBooking(CourtBooking $booking): ?ClubCardTransaction
    {
        if (!$booking->club_card_id) return null;
        if ($booking->card_charged_at) return null; // уже обработана

        $card = ClubCard::find($booking->club_card_id);

        return DB::transaction(function () use ($booking, $card) {
            $freshBooking = CourtBooking::lockForUpdate()->find($booking->id);
            if (!$freshBooking || $freshBooking->card_charged_at) return null;

            $tx = null;
            if ($card) {
                $lockedCard = ClubCard::lockForUpdate()->find($card->id);
                if ($lockedCard) {
                    $tx = ClubCardTransaction::create([
                        'club_id' => $lockedCard->club_id,
                        'club_card_id' => $lockedCard->id,
                        'court_booking_id' => $freshBooking->id,
                        'amount' => 0,
                        'balance_after' => (int) $lockedCard->balance,
                        'note' => 'Не списано (пропущено)',
                    ]);
                }
            }

            $freshBooking->forceFill(['card_charged_at' => now()])->save();
            return $tx;
        });
    }

    /**
     * Бронь действительно завершилась: дата+время окончания уже в прошлом.
     * Время хранится в локальном TZ клуба.
     */
    public function bookingEnded(CourtBooking $booking, ?Carbon $now = null): bool
    {
        $now ??= now();
        $date = $booking->date instanceof Carbon
            ? $booking->date->format('Y-m-d')
            : (string) $booking->date;
        $tz = config('app.schedule_timezone', 'Asia/Almaty');
        $end = Carbon::parse($date . ' ' . substr((string) $booking->end_time, 0, 5), $tz);

        return $end->lessThanOrEqualTo($now);
    }

    /**
     * Брони клуба, ожидающие РУЧНОГО списания: карта-счётчик, confirmed,
     * время прошло, ещё не обработана. Скидочные карты исключаем.
     *
     * @return \Illuminate\Support\Collection<int, CourtBooking>
     */
    public function pendingForClub(Club $club): \Illuminate\Support\Collection
    {
        $courtIds = $club->courts()->pluck('id');
        $now = now();

        return CourtBooking::whereIn('court_id', $courtIds)
            ->whereNotNull('club_card_id')
            ->whereNull('card_charged_at')
            ->where('status', 'confirmed')
            ->whereDate('date', '<=', $now->toDateString())
            ->with(['clubCard.type', 'clubCard.client', 'court:id,name'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get()
            ->filter(fn (CourtBooking $b) => $b->clubCard
                && $b->clubCard->isCounter()
                && $this->bookingEnded($b, $now))
            ->values();
    }

    public function pendingCountForClub(Club $club): int
    {
        return $this->pendingForClub($club)->count();
    }

    private function markCharged(CourtBooking $booking): void
    {
        $booking->forceFill(['card_charged_at' => now()])->save();
    }

    /** Длительность брони в часах (округление до целого). */
    private function bookingHours(CourtBooking $booking): int
    {
        $start = Carbon::parse(substr((string) $booking->start_time, 0, 5));
        $end = Carbon::parse(substr((string) $booking->end_time, 0, 5));
        $minutes = $end->greaterThan($start) ? $start->diffInMinutes($end) : 0;
        return (int) round($minutes / 60);
    }

    /** Срок: явная дата → она; иначе фикс. дата типа; иначе N дней с сегодня; иначе бессрочно. */
    private function resolveExpiry(ClubCardType $type, ?string $expiresAt): ?string
    {
        if ($expiresAt) {
            return $expiresAt;
        }
        if ($type->default_expires_at) {
            return Carbon::parse($type->default_expires_at)->toDateString();
        }
        if ($type->default_validity_days) {
            return Carbon::today()->addDays((int) $type->default_validity_days)->toDateString();
        }
        return null;
    }

    /**
     * Сгенерировать уникальный код карты (8 символов, без похожих 0/O/1/I).
     */
    public function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (ClubCard::where('code', $code)->exists());

        return $code;
    }
}
