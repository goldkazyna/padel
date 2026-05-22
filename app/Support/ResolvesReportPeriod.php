<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

trait ResolvesReportPeriod
{
    /** @return array{0: Carbon, 1: Carbon, 2: string} [from, to, label] */
    protected function parsePeriod(Request $request): array
    {
        $now = Carbon::today();

        switch ($request->get('preset')) {
            case 'today':
                return [$now->copy(), $now->copy(), 'Сегодня'];
            case 'week':
                return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'Эта неделя'];
            case 'month':
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'Этот месяц'];
            case 'prev_month':
                $prev = $now->copy()->subMonthNoOverflow();
                return [$prev->copy()->startOfMonth(), $prev->copy()->endOfMonth(), 'Прошлый месяц'];
        }

        $from = $request->filled('from') ? Carbon::parse($request->get('from')) : $now->copy()->subDays(29);
        $to = $request->filled('to') ? Carbon::parse($request->get('to')) : $now->copy();
        return [$from->startOfDay(), $to->endOfDay(), $from->format('d.m.Y') . ' — ' . $to->format('d.m.Y')];
    }
}
