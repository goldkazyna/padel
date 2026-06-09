<?php

namespace App\Services;

use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use Carbon\Carbon;

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
            'code' => $this->generateCode(),
            'balance' => $balance,
            'initial_balance' => $initial,
            'expires_at' => $this->resolveExpiry($type, $expiresAt),
            'status' => 'active',
        ]);
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
