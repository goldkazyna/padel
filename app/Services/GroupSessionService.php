<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubCoach;
use App\Models\ClubGroupAttendance;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupSession;
use Carbon\Carbon;

/**
 * Общее ядро проведения группового занятия — используется и ручным проведением
 * (GroupSessionController::conduct), и авто-кроном (group-sessions:auto-conduct).
 */
class GroupSessionService
{
    /**
     * Провести занятие: применить посещаемость, пометить «проведено», заморозить
     * выплату тренеру, записать в лог.
     *
     * @param array $rows [memberId => ['status' => charge|trial|absent, 'trial_amount' => ?int]]
     * @param int|null $conductedBy id пользователя (null = система/крон)
     *
     * Правило списания: charge + НЕ заморожен + остаток > 0. Пустой пакет (остаток ≤ 0)
     * — занятие бесплатно (attended=true, charged=false). Заморозка и пробное пакет не тратят.
     */
    public function conduct(ClubGroupSession $session, array $rows, ?int $conductedBy, Club $club): void
    {
        $sessionDate = $session->date->toDateString();

        foreach ($rows as $memberId => $row) {
            $member = ClubGroupMember::find($memberId);
            if (!$member || $member->group_id !== $session->group_id) {
                continue;
            }

            $status = $row['status'] ?? 'absent';
            $frozen = $member->isFrozenOn($sessionDate);
            $notStarted = !$member->hasStartedOn($sessionDate); // ещё не начал ходить
            $isTrial = $status === 'trial';
            // Списываем: charge + не заморожен + уже начал ходить + остаток > 0.
            $charged = $status === 'charge' && !$frozen && !$notStarted && $member->remaining > 0;
            $attended = in_array($status, ['charge', 'trial'], true);
            $trialAmount = $isTrial ? (int) ($row['trial_amount'] ?? 0) : null;

            ClubGroupAttendance::updateOrCreate(
                ['session_id' => $session->id, 'group_member_id' => $member->id],
                ['attended' => $attended, 'charged' => $charged, 'is_trial' => $isTrial, 'trial_amount' => $trialAmount]
            );
        }

        $session->update([
            'status' => 'held',
            'held_at' => now(),
            'conducted_by' => $conductedBy,
        ]);

        $this->freezeCoachPay($session, $club);

        // Лог пишем только для ручного проведения (есть пользователь). В авто-режиме
        // (крон, $conductedBy === null) auth()->id() null, а activity_logs.user_id NOT NULL —
        // факт авто-проведения виден по conducted_by = null.
        if ($conductedBy !== null) {
            ActivityLog::log('updated', 'ClubGroupSession', $session->id,
                "Занятие проведено: «{$session->group->name}»", clubId: $club->id);
        }
    }

    /**
     * Заморозить выплату тренеру по ставке на момент проведения (rate_group × часы),
     * чтобы последующее изменение ставки не меняло прошлые занятия.
     */
    protected function freezeCoachPay(ClubGroupSession $session, Club $club): void
    {
        $booking = $session->courtBooking;
        if (!$booking || !$booking->coach_id) {
            return;
        }

        $coach = ClubCoach::where('club_id', $club->id)
            ->where('user_id', $booking->coach_id)->first();
        $sM = Carbon::parse($booking->start_time)->hour * 60 + Carbon::parse($booking->start_time)->minute;
        $eM = Carbon::parse($booking->end_time)->hour * 60 + Carbon::parse($booking->end_time)->minute;
        if ($eM <= $sM) {
            $eM += 1440;
        }
        $hrs = ($eM - $sM) / 60;
        $price = ($coach && $coach->rate_group !== null)
            ? (float) $coach->rate_group * $hrs
            : (float) ($coach?->getRateForHours((int) $hrs) ?? 0);
        $booking->update(['coach_price' => $price]);
    }
}
