<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\User;
use Carbon\Carbon;

class FinanceReportService
{
    use CalculatesBookingRevenue;

    private function confirmed(Club $club, Carbon $from, Carbon $to)
    {
        return CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('court')
            ->orderBy('date')->orderBy('start_time')
            ->get();
    }

    private function managerName(?int $id, array &$cache): string
    {
        if (!$id) return '';
        if (!isset($cache[$id])) {
            $u = User::find($id);
            $cache[$id] = $u ? ($u->name ?: trim($u->first_name . ' ' . $u->last_name)) : "ID {$id}";
        }
        return $cache[$id];
    }

    private function parseDate($date): Carbon
    {
        return $date instanceof Carbon ? $date : Carbon::parse((string) $date);
    }

    public function sales(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->confirmed($club, $from, $to);
        $names = [];
        $rows = []; $tAmount = 0; $tDiscount = 0;
        foreach ($bookings as $b) {
            $amount = $this->bookingRevenue($b, $club->id);
            $rows[] = [
                $this->parseDate($b->date)->format('d.m.Y'),
                Carbon::parse($b->start_time)->format('H:i'),
                $b->court->name ?? '',
                $b->client_name ?? '',
                $b->client_phone ?? '',
                round($amount, 2),
                round((float) $b->discount, 2),
                $b->payment_method ?? '',
                $b->is_paid ? 'Да' : 'Нет',
                $this->managerName($b->booked_by, $names),
            ];
            $tAmount += $amount; $tDiscount += (float) $b->discount;
        }
        return new ReportSheet(
            title: 'Продажи',
            headings: ['Дата', 'Время', 'Корт', 'Клиент', 'Телефон', 'Сумма', 'Скидка', 'Оплата', 'Оплачено', 'Менеджер'],
            rows: $rows,
            totals: ['Итого', '', '', '', '', round($tAmount, 2), round($tDiscount, 2), '', '', ''],
            columnFormats: [4 => '@', 5 => '#,##0', 6 => '#,##0'],
        );
    }

    private function aggregate(Club $club, Carbon $from, Carbon $to, callable $keyFn, callable $labelFn, string $title, string $colName): ReportSheet
    {
        $bookings = $this->confirmed($club, $from, $to);
        $cnt = []; $sum = [];
        foreach ($bookings as $b) {
            $k = $keyFn($b);
            $cnt[$k] = ($cnt[$k] ?? 0) + 1;
            $sum[$k] = ($sum[$k] ?? 0) + $this->bookingRevenue($b, $club->id);
        }
        ksort($cnt);
        $rows = []; $tC = 0; $tS = 0;
        foreach ($cnt as $k => $c) {
            $avg = $c > 0 ? $sum[$k] / $c : 0;
            $rows[] = [$labelFn($k), $c, round($sum[$k], 2), round($avg, 2)];
            $tC += $c; $tS += $sum[$k];
        }
        return new ReportSheet(
            title: $title,
            headings: [$colName, 'Броней', 'Сумма', 'Средний чек'],
            rows: $rows,
            totals: ['Итого', $tC, round($tS, 2), $tC > 0 ? round($tS / $tC, 2) : 0],
            columnFormats: [2 => '#,##0', 3 => '#,##0'],
        );
    }

    public function byDays(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        return $this->aggregate($club, $from, $to,
            fn ($b) => $this->parseDate($b->date)->format('Y-m-d'),
            fn ($k) => Carbon::parse($k)->format('d.m.Y'),
            'Продажи по дням', 'Дата');
    }

    public function byWeeks(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        return $this->aggregate($club, $from, $to,
            fn ($b) => $this->parseDate($b->date)->format('o-W'),
            function ($k) {
                [$year, $week] = explode('-', $k);
                $start = Carbon::now()->setISODate((int) $year, (int) $week)->startOfWeek();
                return $start->format('d.m') . '–' . $start->copy()->endOfWeek()->format('d.m.Y');
            },
            'Продажи по неделям', 'Неделя');
    }

    public function byMonths(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        return $this->aggregate($club, $from, $to,
            fn ($b) => $this->parseDate($b->date)->format('Y-m'),
            fn ($k) => Carbon::parse($k . '-01')->format('m.Y'),
            'Продажи по месяцам', 'Месяц');
    }

    public function debts(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->confirmed($club, $from, $to)->where('is_paid', false);
        $names = [];
        $rows = []; $tDebt = 0;
        foreach ($bookings as $b) {
            $amount = $this->bookingRevenue($b, $club->id);
            $rows[] = [
                $this->parseDate($b->date)->format('d.m.Y'),
                $b->court->name ?? '',
                $b->client_name ?? '',
                $b->client_phone ?? '',
                round($amount, 2),
                $this->managerName($b->booked_by, $names),
            ];
            $tDebt += $amount;
        }
        return new ReportSheet(
            title: 'Задолженности',
            headings: ['Дата', 'Корт', 'Клиент', 'Телефон', 'Сумма', 'Менеджер'],
            rows: $rows,
            totals: ['Итого', '', '', '', round($tDebt, 2), ''],
            columnFormats: [3 => '@', 4 => '#,##0'],
        );
    }
}
