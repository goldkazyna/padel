<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\User;
use Carbon\Carbon;

class ManagersReportService
{
    public function sales(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereNotNull('booked_by')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('booked_by')->orderBy('date')
            ->get();

        $names = [];
        $agg = []; // "userId|date" => [count, sum, paid]
        foreach ($bookings as $b) {
            $dateKey = ($b->date instanceof \Carbon\Carbon ? $b->date : Carbon::parse((string) $b->date))->format('Y-m-d');
            $key = $b->booked_by . '|' . $dateKey;
            $agg[$key] ??= [0, 0.0, 0.0];
            $amount = (float) $b->price - (float) $b->discount;
            $agg[$key][0]++;
            $agg[$key][1] += $amount;
            if ($b->is_paid) $agg[$key][2] += $amount;
        }
        ksort($agg);

        $rows = []; $tC = 0; $tS = 0; $tP = 0;
        foreach ($agg as $key => [$c, $s, $p]) {
            [$uid, $date] = explode('|', $key);
            if (!isset($names[$uid])) {
                $u = User::find($uid);
                $names[$uid] = $u ? ($u->name ?: trim($u->first_name . ' ' . $u->last_name)) : "ID {$uid}";
            }
            $rows[] = [$names[$uid], Carbon::parse($date)->format('d.m.Y'), $c, round($s, 2), round($p, 2)];
            $tC += $c; $tS += $s; $tP += $p;
        }

        return new ReportSheet(
            title: 'Продажи менеджеров',
            headings: ['Менеджер', 'Дата (смена)', 'Броней', 'Сумма', 'Оплачено'],
            rows: $rows,
            totals: ['Итого', '', $tC, round($tS, 2), round($tP, 2)],
            columnFormats: [3 => '#,##0', 4 => '#,##0'],
        );
    }
}
