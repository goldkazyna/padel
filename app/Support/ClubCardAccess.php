<?php

namespace App\Support;

use App\Models\ClubClient;
use App\Models\User;

/**
 * Связка игрока (users) с клиентами клубов (club_clients), чтобы показывать/
 * использовать его клубные карты: явная привязка user_id из CRM + совпадение
 * по номеру телефона (по последним 10 цифрам, терпимо к формату).
 */
class ClubCardAccess
{
    /** ID клиентов клубов, принадлежащих игроку. */
    public static function clientIdsForUser(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $ids = ClubClient::where('user_id', $user->id)->pluck('id')->all();

        $digits = preg_replace('/\D+/', '', (string) $user->phone);
        if (strlen($digits) >= 10) {
            $last10 = substr($digits, -10);
            $tail = substr($last10, -8);
            $byPhone = ClubClient::whereNotNull('phone')
                ->where('phone', 'like', '%' . $tail . '%')
                ->pluck('phone', 'id')
                ->filter(fn($phone) => substr(preg_replace('/\D+/', '', (string) $phone), -10) === $last10)
                ->keys()
                ->all();
            $ids = array_merge($ids, $byPhone);
        }

        return array_values(array_unique($ids));
    }
}
