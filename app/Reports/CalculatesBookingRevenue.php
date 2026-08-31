<?php

namespace App\Reports;

use App\Models\ClubCoach;
use Carbon\Carbon;

trait CalculatesBookingRevenue
{
    /** @var array<string, \App\Models\ClubCoach|null> */
    private array $coachProfileCache = [];

    /**
     * Полная выручка с брони: сколько платит клиент + стоимость тренера.
     *
     * price уже за вычетом скидки — так его считает форма брони
     * (`price = цена по прайсу − скидка`). Второе вычитание занижало
     * выручку на размер скидки и уводило суммы в минус, если скидка
     * была больше остатка.
     */
    protected function bookingRevenue($booking, int $clubId): float
    {
        $amount = (float) $booking->price;
        if ($booking->coach_id) {
            $profile = $this->coachProfile((int) $booking->coach_id, $clubId);
            if ($profile) {
                $amount += $profile->getRateForHours($this->bookingWholeHours($booking->start_time, $booking->end_time));
            }
        }
        return $amount;
    }

    private function coachProfile(int $userId, int $clubId): ?ClubCoach
    {
        $key = $clubId . '|' . $userId;
        if (!array_key_exists($key, $this->coachProfileCache)) {
            $this->coachProfileCache[$key] = ClubCoach::where('club_id', $clubId)
                ->where('user_id', $userId)->first();
        }
        return $this->coachProfileCache[$key];
    }

    private function bookingWholeHours(string $start, string $end): int
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        if ($e->lessThanOrEqualTo($s)) $e->addDay();
        return (int) floor($s->floatDiffInRealHours($e));
    }
}
