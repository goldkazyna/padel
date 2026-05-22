<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\CourtBooking;
use Carbon\Carbon;

class ClubLoadReportService
{
    private const WEEKDAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    private function hoursBetween(string $start, string $end): float
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        if ($e->lessThanOrEqualTo($s)) $e->addDay();
        return $s->floatDiffInRealHours($e);
    }

    /** Confirmed bookings of the club in [from,to]. */
    private function bookings(Club $club, Carbon $from, Carbon $to)
    {
        return CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();
    }

    public function byHours(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $courts = $club->courts()->where('is_active', true)->get();
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $bookings = $this->bookings($club, $from, $to);

        $minOpen = 23; $maxClose = 0;
        foreach ($courts as $c) {
            $minOpen = min($minOpen, (int) Carbon::parse($c->open_time)->hour);
            $closeH = (int) Carbon::parse($c->close_time)->hour;
            $maxClose = max($maxClose, $closeH === 0 ? 24 : $closeH);
        }
        if ($courts->isEmpty()) { $minOpen = 8; $maxClose = 22; }

        $rows = [];
        $totOcc = 0; $totAvail = 0;
        for ($h = $minOpen; $h < $maxClose; $h++) {
            $availCourts = $courts->filter(function ($c) use ($h) {
                $o = (int) Carbon::parse($c->open_time)->hour;
                $cl = (int) Carbon::parse($c->close_time)->hour; $cl = $cl === 0 ? 24 : $cl;
                return $h >= $o && $h < $cl;
            })->count();
            $avail = $availCourts * $days;

            $occ = 0.0;
            foreach ($bookings as $b) {
                $bs = (float) Carbon::parse($b->start_time)->hour + Carbon::parse($b->start_time)->minute / 60;
                $beH = Carbon::parse($b->end_time);
                $be = (float) $beH->hour + $beH->minute / 60;
                if ($be <= $bs) $be += 24;
                $overlap = max(0, min($be, $h + 1) - max($bs, $h));
                $occ += $overlap;
            }

            $rows[] = [sprintf('%02d:00', $h), round($occ, 2), round($avail, 2), $avail > 0 ? round($occ / $avail, 4) : 0];
            $totOcc += $occ; $totAvail += $avail;
        }

        return new ReportSheet(
            title: 'Загрузка по часам',
            headings: ['Час', 'Занято, ч', 'Доступно, ч', 'Загрузка'],
            rows: $rows,
            totals: ['Итого', round($totOcc, 2), round($totAvail, 2), $totAvail > 0 ? round($totOcc / $totAvail, 4) : 0],
            columnFormats: [1 => '#,##0.0', 2 => '#,##0.0', 3 => '0%'],
        );
    }

    public function byWeekdays(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->bookings($club, $from, $to);
        $occ = array_fill(1, 7, 0.0); $cnt = array_fill(1, 7, 0);
        foreach ($bookings as $b) {
            $dow = Carbon::parse($b->date)->isoWeekday();
            $occ[$dow] += $this->hoursBetween($b->start_time, $b->end_time);
            $cnt[$dow]++;
        }
        $rows = []; $totOcc = 0; $totCnt = 0;
        foreach (self::WEEKDAYS as $i => $name) {
            $rows[] = [$name, round($occ[$i], 2), '', '', $cnt[$i]];
            $totOcc += $occ[$i]; $totCnt += $cnt[$i];
        }
        return new ReportSheet(
            title: 'Загрузка по дням недели',
            headings: ['День', 'Занято, ч', '', '', 'Броней'],
            rows: $rows,
            totals: ['Итого', round($totOcc, 2), '', '', $totCnt],
            columnFormats: [1 => '#,##0.0'],
        );
    }

    public function byMonths(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->bookings($club, $from, $to);
        $occ = []; $cnt = [];
        foreach ($bookings as $b) {
            $key = Carbon::parse($b->date)->format('m.Y');
            $occ[$key] = ($occ[$key] ?? 0) + $this->hoursBetween($b->start_time, $b->end_time);
            $cnt[$key] = ($cnt[$key] ?? 0) + 1;
        }
        ksort($occ);
        $rows = []; $totOcc = 0; $totCnt = 0;
        foreach ($occ as $key => $hours) {
            $rows[] = [$key, round($hours, 2), '', '', $cnt[$key]];
            $totOcc += $hours; $totCnt += $cnt[$key];
        }
        return new ReportSheet(
            title: 'Загрузка по месяцам',
            headings: ['Месяц', 'Занято, ч', '', '', 'Броней'],
            rows: $rows,
            totals: ['Итого', round($totOcc, 2), '', '', $totCnt],
            columnFormats: [1 => '#,##0.0'],
        );
    }
}
