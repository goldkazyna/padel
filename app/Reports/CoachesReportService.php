<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\ClubCoach;
use App\Models\CourtBooking;
use App\Models\User;
use Carbon\Carbon;

class CoachesReportService
{
    private function hours(string $start, string $end): float
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        if ($e->lessThanOrEqualTo($s)) {
            $e->addDay();
        }
        return round($s->floatDiffInRealHours($e), 2);
    }

    private function coachBookings(Club $club, Carbon $from, Carbon $to)
    {
        return CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereNotNull('coach_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('court')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();
    }

    private function coachName(int $userId, array &$cache): string
    {
        if (!isset($cache[$userId])) {
            $u = User::find($userId);
            $cache[$userId] = $u
                ? ($u->name ?: trim($u->first_name . ' ' . $u->last_name))
                : "ID {$userId}";
        }
        return $cache[$userId];
    }

    private function formatDate($date): string
    {
        if ($date instanceof Carbon) {
            return $date->format('d.m.Y');
        }
        return Carbon::parse((string) $date)->format('d.m.Y');
    }

    public function usage(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $agg = []; // userId => [sessions, hours, income]
        foreach ($bookings as $b) {
            $id = $b->coach_id;
            $agg[$id] ??= [0, 0.0, 0.0];
            $agg[$id][0]++;
            $agg[$id][1] += $this->hours($b->start_time, $b->end_time);
            $agg[$id][2] += (float) $b->price - (float) $b->discount;
        }
        $rows = [];
        $tS = 0;
        $tH = 0.0;
        $tI = 0.0;
        foreach ($agg as $id => [$s, $h, $i]) {
            $rows[] = [$this->coachName($id, $names), $s, round($h, 2), round($i, 2)];
            $tS += $s;
            $tH += $h;
            $tI += $i;
        }
        usort($rows, fn($a, $b) => $b[1] <=> $a[1]);
        return new ReportSheet(
            title: 'Использование услуг (тренеры)',
            headings: ['Тренер', 'Занятий', 'Часов', 'Доход клуба'],
            rows: $rows,
            totals: ['Итого', $tS, round($tH, 2), round($tI, 2)],
            columnFormats: [2 => '#,##0.0', 3 => '#,##0'],
        );
    }

    public function sessions(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $typeLabels = [
            'soft'         => 'Мягкая',
            'group'        => 'Групповая',
            'individual'   => 'Индивид.',
            'tournament'   => 'Турнир',
        ];
        $rows = [];
        $tAmount = 0.0;
        foreach ($bookings as $b) {
            $amount = (float) $b->price - (float) $b->discount;
            $rows[] = [
                $this->formatDate($b->date),
                Carbon::parse($b->start_time)->format('H:i') . '–' . Carbon::parse($b->end_time)->format('H:i'),
                $b->court->name ?? '',
                $this->coachName($b->coach_id, $names),
                $b->client_name ?? '',
                $this->hours($b->start_time, $b->end_time),
                $typeLabels[$b->booking_type] ?? '',
                round($amount, 2),
            ];
            $tAmount += $amount;
        }
        return new ReportSheet(
            title: 'Проведённые тренировки',
            headings: ['Дата', 'Время', 'Корт', 'Тренер', 'Клиент', 'Часов', 'Тип', 'Сумма'],
            rows: $rows,
            totals: ['Итого', '', '', '', '', '', '', round($tAmount, 2)],
            columnFormats: [5 => '#,##0.0', 7 => '#,##0'],
        );
    }

    public function salary(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $profiles = ClubCoach::where('club_id', $club->id)->get()->keyBy('user_id');

        $agg = []; // userId => [sessions, hours, pay]
        foreach ($bookings as $b) {
            $h = $this->hours($b->start_time, $b->end_time);
            $profile = $profiles->get($b->coach_id);
            $wholeHours = (int) floor($h);
            $pay = $profile ? $profile->getRateForHours($wholeHours) : 0.0;

            $id = $b->coach_id;
            $agg[$id] ??= [0, 0.0, 0.0];
            $agg[$id][0]++;
            $agg[$id][1] += $h;
            $agg[$id][2] += $pay;
        }
        $rows = [];
        $tS = 0;
        $tH = 0.0;
        $tP = 0.0;
        foreach ($agg as $id => [$s, $h, $p]) {
            $rows[] = [$this->coachName($id, $names), $s, round($h, 2), round($p, 2)];
            $tS += $s;
            $tH += $h;
            $tP += $p;
        }
        usort($rows, fn($a, $b) => $b[3] <=> $a[3]);
        return new ReportSheet(
            title: 'Зарплата тренеров',
            headings: ['Тренер', 'Занятий', 'Часов', 'К начислению'],
            rows: $rows,
            totals: ['Итого', $tS, round($tH, 2), round($tP, 2)],
            columnFormats: [2 => '#,##0.0', 3 => '#,##0'],
        );
    }
}
