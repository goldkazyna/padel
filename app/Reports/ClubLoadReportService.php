<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
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

    /** Hour-of-day float (e.g. 9.5 for 09:30). */
    private function hourFloat(string $time): float
    {
        $t = Carbon::parse($time);
        return $t->hour + $t->minute / 60;
    }

    private function activeCourts(Club $club)
    {
        return $club->courts()->where('is_active', true)->get();
    }

    private function bookings(Club $club, Carbon $from, Carbon $to)
    {
        return CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();
    }

    private function blocks(Club $club, Carbon $from, Carbon $to)
    {
        return CourtBlock::whereIn('court_id', $club->courts()->pluck('id'))
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get();
    }

    /** Open hours of a court per day (handles past-midnight close). */
    private function courtOpenHours($court): float
    {
        return $this->hoursBetween($court->open_time, $court->close_time);
    }

    /**
     * Available hours grouped by a key derived from each date in [from,to]:
     * sum of active-court open hours minus blocked hours on that date.
     * @param callable $keyFn fn(Carbon $date): string
     * @return array<string,float>
     */
    private function availableByDate(Club $club, Carbon $from, Carbon $to, callable $keyFn): array
    {
        $courts = $this->activeCourts($club);
        $dailyCourtHours = $courts->sum(fn ($c) => $this->courtOpenHours($c));

        // blocks grouped by date string (Y-m-d)
        $blocksByDate = [];
        foreach ($this->blocks($club, $from, $to) as $bl) {
            $d = ($bl->date instanceof \Carbon\Carbon ? $bl->date : Carbon::parse((string) $bl->date))->toDateString();
            $blocksByDate[$d] = ($blocksByDate[$d] ?? 0) + $this->hoursBetween($bl->start_time, $bl->end_time);
        }

        $result = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $dateStr = $cursor->toDateString();
            $avail = $dailyCourtHours - ($blocksByDate[$dateStr] ?? 0);
            if ($avail < 0) $avail = 0;
            $key = $keyFn($cursor);
            $result[$key] = ($result[$key] ?? 0) + $avail;
            $cursor->addDay();
        }
        return $result;
    }

    /** Открыт ли корт в час [h, h+1)? Поддерживает круглосуточный и ночной (через полночь) режим. */
    private function isOpenAtHour($court, int $h): bool
    {
        $o = (int) Carbon::parse($court->open_time)->hour;
        $c = (int) Carbon::parse($court->close_time)->hour;
        if ($o === $c) return true;                 // open == close → круглосуточно
        if ($o < $c) return $h >= $o && $h < $c;    // обычный режим
        return $h >= $o || $h < $c;                 // ночной: закрытие после полуночи
    }

    /** Часы интервала [start,end) (с переходом через полночь), попадающие в окно [h, h+1). */
    private function hourOverlap(string $start, string $end, int $h): float
    {
        $bs = $this->hourFloat($start);
        $be = $this->hourFloat($end);
        if ($be <= $bs) $be += 24;
        $ov = max(0, min($be, $h + 1) - max($bs, $h));
        $ov += max(0, min($be, $h + 25) - max($bs, $h + 24)); // часть после полуночи
        return $ov;
    }

    public function byHours(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $courts = $this->activeCourts($club);
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $bookings = $this->bookings($club, $from, $to);
        $blocks = $this->blocks($club, $from, $to);

        $rows = [];
        $totOcc = 0; $totAvail = 0;
        for ($h = 0; $h < 24; $h++) {
            $availCourts = $courts->filter(fn ($c) => $this->isOpenAtHour($c, $h))->count();
            $avail = $availCourts * $days;

            $blocked = 0.0;
            foreach ($blocks as $bl) {
                $blocked += $this->hourOverlap($bl->start_time, $bl->end_time, $h);
            }
            $avail = max(0, $avail - $blocked);

            $occ = 0.0;
            foreach ($bookings as $b) {
                $occ += $this->hourOverlap($b->start_time, $b->end_time, $h);
            }

            // пропускаем часы, когда ни один корт не открыт и нет броней
            if ($availCourts === 0 && $occ <= 0) continue;

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
        $avail = $this->availableByDate($club, $from, $to, fn (Carbon $d) => (string) $d->isoWeekday());

        $rows = []; $totOcc = 0; $totAvail = 0; $totCnt = 0;
        foreach (self::WEEKDAYS as $i => $name) {
            $a = $avail[(string) $i] ?? 0;
            $rows[] = [$name, round($occ[$i], 2), round($a, 2), $a > 0 ? round($occ[$i] / $a, 4) : 0, $cnt[$i]];
            $totOcc += $occ[$i]; $totAvail += $a; $totCnt += $cnt[$i];
        }
        return new ReportSheet(
            title: 'Загрузка по дням недели',
            headings: ['День', 'Занято, ч', 'Доступно, ч', 'Загрузка', 'Броней'],
            rows: $rows,
            totals: ['Итого', round($totOcc, 2), round($totAvail, 2), $totAvail > 0 ? round($totOcc / $totAvail, 4) : 0, $totCnt],
            columnFormats: [1 => '#,##0.0', 2 => '#,##0.0', 3 => '0%'],
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
        $avail = $this->availableByDate($club, $from, $to, fn (Carbon $d) => $d->format('m.Y'));

        ksort($occ);
        // ensure months that have availability but no bookings still appear
        foreach (array_keys($avail) as $k) { if (!isset($occ[$k])) $occ[$k] = 0; }
        uksort($occ, fn ($a, $b) => Carbon::createFromFormat('m.Y', $a)->timestamp <=> Carbon::createFromFormat('m.Y', $b)->timestamp);

        $rows = []; $totOcc = 0; $totAvail = 0; $totCnt = 0;
        foreach ($occ as $key => $hours) {
            $a = $avail[$key] ?? 0;
            $c = $cnt[$key] ?? 0;
            $rows[] = [$key, round($hours, 2), round($a, 2), $a > 0 ? round($hours / $a, 4) : 0, $c];
            $totOcc += $hours; $totAvail += $a; $totCnt += $c;
        }
        return new ReportSheet(
            title: 'Загрузка по месяцам',
            headings: ['Месяц', 'Занято, ч', 'Доступно, ч', 'Загрузка', 'Броней'],
            rows: $rows,
            totals: ['Итого', round($totOcc, 2), round($totAvail, 2), $totAvail > 0 ? round($totOcc / $totAvail, 4) : 0, $totCnt],
            columnFormats: [1 => '#,##0.0', 2 => '#,##0.0', 3 => '0%'],
        );
    }
}
