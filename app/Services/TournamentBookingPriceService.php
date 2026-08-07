<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\Tournament;
use App\Models\TournamentTeam;

/**
 * Цена турнирных броней корта.
 *
 * Сумма за турнир на дату = цена турнира × число оплативших участников.
 * Она делится поровну между всеми подтверждёнными бронями турнира на эту дату.
 */
class TournamentBookingPriceService
{
    /**
     * Статусы турниров, которые предлагаем в селекте брони.
     * 'closed' — турнир в день игры (запись закрыта), именно тогда и бронируют корт.
     */
    public const PICKER_STATUSES = ['open', 'in_progress', 'closed'];

    /**
     * Кеш числа оплативших участников на время одного вызова сервиса:
     * id турнира => count. approvedParticipantsCount() бьёт в БД, а внутри
     * pickerData() один и тот же турнир проходит через totalForDate()
     * до 7 раз (по числу видимых дат) плюс отдельно для paid_count — без
     * кеша это лишние SQL-запросы на заведомо неизменное за один HTTP-запрос
     * значение.
     *
     * @var array<int, int>
     */
    private array $approvedCountCache = [];

    /**
     * Число оплативших участников турнира с кешированием на время вызова.
     */
    private function approvedCount(Tournament $tournament): int
    {
        return $this->approvedCountCache[$tournament->id]
            ??= $tournament->approvedParticipantsCount();
    }

    /**
     * Сколько всего должен стоить турнир на дату (до деления между кортами).
     */
    public function totalForDate(Tournament $tournament): float
    {
        // approvedCount() сам различает личные турниры (статус 'registered')
        // и командные ('approved'-пары × 2) — свою логику не пишем, только кешируем.
        return (float) $tournament->price * $this->approvedCount($tournament);
    }

    /**
     * Разложить сумму турнира по его броням на дату.
     * Возвращает true, если хотя бы одна цена изменилась.
     */
    public function syncForDate(Tournament $tournament, string $date): bool
    {
        $bookings = CourtBooking::where('tournament_id', $tournament->id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        if ($bookings->isEmpty()) {
            return false;
        }

        // Бронь на выключенном корте в расписании не видна. Если оставить ей долю,
        // часть суммы турнира «исчезнет» с экрана (из 100 000 видно 50 000).
        // Поэтому делим только между видимыми бронями, а невидимым ставим 0 —
        // так деньги не пропадают и не задваиваются в отчётах.
        $activeCourtIds = Court::whereIn('id', $bookings->pluck('court_id')->unique()->all())
            ->where('is_active', true)
            ->pluck('id')
            ->all();
        $hiddenBookings = $bookings->whereNotIn('court_id', $activeCourtIds);
        $bookings = $bookings->whereIn('court_id', $activeCourtIds)->values();

        // Все корты турнира выключены — делить не между кем, цены не трогаем,
        // чтобы не обнулить уже проведённые деньги.
        if ($bookings->isEmpty()) {
            return false;
        }

        $changed = false;
        foreach ($hiddenBookings as $hidden) {
            if ((float) $hidden->price !== 0.0) {
                $hidden->update(['price' => 0, 'discount' => 0]);
                $changed = true;
            }
        }

        $total = $this->totalForDate($tournament);
        $count = $bookings->count();

        // Делим до копеек, остаток отдаём первой броне, чтобы сумма сошлась.
        $share = floor($total / $count * 100) / 100;
        $remainder = round($total - $share * $count, 2);

        foreach ($bookings as $i => $booking) {
            $price = $i === 0 ? round($share + $remainder, 2) : $share;
            if ((float) $booking->price !== $price) {
                // Скидка турнирной броне не применяется — цену задаёт турнир.
                $booking->update(['price' => $price, 'discount' => 0]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Пересчитать набор, в который входит бронь. Безопасно вызывать
     * для любой брони — не турнирные просто игнорируются.
     */
    public function syncForBooking(CourtBooking $booking): void
    {
        if (!$booking->tournament_id) {
            return;
        }

        $tournament = Tournament::find($booking->tournament_id);
        if ($tournament) {
            $this->syncForDate($tournament, $booking->date->format('Y-m-d'));
        }
    }

    /**
     * Данные о турнирах клуба для выпадающего списка в модалке брони.
     * Попутно пересчитывает цены броней в видимом диапазоне дат —
     * это и есть живой пересчёт при открытии расписания.
     *
     * @param  array<string> $dates даты видимого диапазона, формат Y-m-d
     * @param  array<int>    $includeTournamentIds турниры уже существующих броней —
     *         добавляются в список независимо от статуса
     * @return array<int, array<string, mixed>> ключ — id турнира
     */
    public function pickerData(Club $club, array $dates, array $includeTournamentIds = []): array
    {
        // На случай, если сервис переживёт несколько вызовов pickerData() на
        // одном экземпляре — кеш не должен утекать между ними.
        $this->approvedCountCache = [];

        $includeTournamentIds = array_values(array_unique(array_filter($includeTournamentIds)));

        $tournaments = Tournament::where('club_id', $club->id)
            ->where(function ($q) use ($includeTournamentIds) {
                $q->whereIn('status', self::PICKER_STATUSES);
                // Турнир уже существующей брони добавляем в любом статусе: турнир
                // после завершения становится 'completed', и без этого его бронь
                // осталась бы без опции в селекте — то есть нередактируемой.
                if ($includeTournamentIds) {
                    $q->orWhereIn('id', $includeTournamentIds);
                }
            })
            ->with([
                'participants' => fn ($q) => $q->wherePivot('status', 'registered'),
                'teams' => fn ($q) => $q->where('status', TournamentTeam::STATUS_APPROVED)
                    ->with(['player1', 'player2']),
            ])
            ->orderBy('start_date', 'desc')
            ->get();

        if ($tournaments->isEmpty()) {
            return [];
        }

        // Брони всех турниров клуба в видимом диапазоне — одним запросом.
        // Иначе на каждый турнир × дату уходил отдельный SQL, и стоимость самого
        // горячего экрана росла линейно от числа турниров клуба.
        // Сравниваем через whereDate: в БД дата может лежать с временем 00:00:00,
        // и точное сравнение со строкой 'Y-m-d' тогда ничего не находит.
        $sortedDates = array_values(array_unique($dates));
        sort($sortedDates);
        $visibleDates = array_flip($sortedDates);

        $bookingsByTournament = empty($sortedDates) ? collect() : CourtBooking::whereIn('tournament_id', $tournaments->pluck('id')->all())
            ->whereDate('date', '>=', reset($sortedDates))
            ->whereDate('date', '<=', end($sortedDates))
            ->where('status', 'confirmed')
            // Брони выключенных кортов в делении не участвуют (см. syncForDate) —
            // значит и в предпросмотре деления их считать нельзя.
            ->whereHas('court', fn ($q) => $q->where('is_active', true))
            ->get(['id', 'tournament_id', 'date', 'court_id'])
            ->filter(fn ($b) => isset($visibleDates[$b->date->format('Y-m-d')]))
            ->groupBy('tournament_id');

        // Пересчитываем только сегодня и будущее: прошлые даты — уже закрытая
        // выручка, и её нельзя менять задним числом просто от просмотра
        // старой даты (в недельном виде есть навигация назад).
        $today = now()->toDateString();

        $result = [];
        foreach ($tournaments as $t) {
            // Считаем один раз на турнир — дальше totalForDate() внутри
            // syncForDate() (по каждой дате) и total ниже берут из кеша.
            $paidCount = $this->approvedCount($t);

            // Сколько турнирных броней уже есть на каждую дату диапазона —
            // клиент по этому числу показывает предварительное деление.
            $bookingsByDate = ($bookingsByTournament[$t->id] ?? collect())
                ->groupBy(fn ($b) => $b->date->format('Y-m-d'))
                ->map->count()
                ->toArray();

            // Даты без броней пересчитывать нечего — не тратим запросы.
            foreach (array_keys($bookingsByDate) as $date) {
                if ($date >= $today) {
                    $this->syncForDate($t, $date);
                }
            }

            $result[$t->id] = [
                'id' => $t->id,
                'name' => $t->name,
                'date' => $t->start_date?->format('d.m'),
                'price' => (float) $t->price,
                'paid_count' => $paidCount,
                'total' => $this->totalForDate($t),
                'participants' => $this->participantNames($t),
                'bookings_by_date' => $bookingsByDate,
            ];
        }

        return $result;
    }

    /**
     * Имена оплативших участников — ровно те, кого посчитал
     * approvedParticipantsCount(): у командных турниров считаются пары,
     * значит и имена берём из пар, иначе список был бы пуст при ненулевом счётчике.
     *
     * @return array<string>
     */
    private function participantNames(Tournament $tournament): array
    {
        if (!$tournament->usesSoloRegistration()) {
            $names = [];
            foreach ($tournament->teams->where('status', TournamentTeam::STATUS_APPROVED) as $team) {
                foreach ([$team->player1, $team->player2] as $player) {
                    if ($player) {
                        $names[] = trim($player->full_name);
                    }
                }
            }

            return array_values(array_filter($names, fn ($n) => $n !== ''));
        }

        // full_name, а не name: у части игроков name пустой (регистрация по телефону),
        // имя лежит в first_name/last_name — иначе в списке были бы пустые строки.
        $names = $tournament->participants->map(fn ($u) => trim($u->full_name))->all();

        return array_values(array_filter($names, fn ($n) => $n !== ''));
    }
}
