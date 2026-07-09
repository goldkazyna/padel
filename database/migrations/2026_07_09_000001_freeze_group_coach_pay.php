<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;

/**
 * Разовая заморозка выплаты тренеру для групповых занятий.
 *
 *  - Проведённые (held) занятия: фиксируем coach_price = rate_group × часы
 *    по текущей групповой ставке тренера (для тех, где ставка не задана —
 *    по базовой). После этого изменение rate_group на них не влияет.
 *  - Непроведённые занятия: снимаем «прилипшую» старую coach_price, чтобы
 *    в расписании показывалась актуальная ставка (живая прикидка).
 *
 * Дальше coach_price для групп проставляется только в момент «проведено»
 * (GroupSessionController::hold).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sessions = \App\Models\ClubGroupSession::whereNotNull('court_booking_id')
            ->with('courtBooking')
            ->get();

        foreach ($sessions as $s) {
            $booking = $s->courtBooking;
            if (!$booking || !$booking->coach_id) {
                continue;
            }

            if ($s->status !== 'held') {
                // Не проведено — убираем старую замороженную цену.
                if ($booking->coach_price !== null) {
                    $booking->update(['coach_price' => null]);
                }
                continue;
            }

            // Проведено — фиксируем по текущей ставке тренера этого клуба.
            $clubId = optional($booking->court)->club_id;
            $coach = \App\Models\ClubCoach::where('user_id', $booking->coach_id)
                ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                ->first();
            if (!$coach) {
                continue;
            }

            $sM = Carbon::parse($booking->start_time)->hour * 60 + Carbon::parse($booking->start_time)->minute;
            $eM = Carbon::parse($booking->end_time)->hour * 60 + Carbon::parse($booking->end_time)->minute;
            if ($eM <= $sM) {
                $eM += 1440;
            }
            $hrs = ($eM - $sM) / 60;

            $frozen = $coach->rate_group !== null
                ? (float) $coach->rate_group * $hrs
                : (float) ($coach->getRateForHours((int) $hrs) ?? 0);

            $booking->update(['coach_price' => $frozen]);
        }
    }

    public function down(): void
    {
        // Разовая нормализация данных — откат не предусмотрен.
    }
};
