<?php

namespace App\Reports;

use App\Models\ClubGroupSession;
use App\Models\Club;
use App\Models\CourtBooking;
use Carbon\Carbon;

use function app;

/**
 * Доходы клуба в разрезе категорий:
 *  - Групповые: проведённые занятия × число списанных участников × цена занятия.
 *  - Методы оплаты (нал/карта/Kaspi/сертификат/депозит/кешбэк): оплаченные брони (цена − скидка).
 *  - Клубная карта: списанные часы × (цена карты / номинал часов).
 */
class ClubIncomeReportService
{
    /** Длительность брони в часах (целое). */
    private function bookingHours(CourtBooking $b): int
    {
        $s = Carbon::parse(substr((string) $b->start_time, 0, 5));
        $e = Carbon::parse(substr((string) $b->end_time, 0, 5));
        // Бронь через полночь (например 21:00–00:00): конец — на следующий день.
        if ($e->lessThanOrEqualTo($s)) {
            $e->addDay();
        }
        $minutes = $s->diffInMinutes($e);
        return (int) round($minutes / 60);
    }

    public function breakdown(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $courtIds = $club->courts()->pluck('id');
        $fromD = $from->toDateString();
        $toD = $to->toDateString();

        // 1) Методы оплаты — оплаченные брони (групповые сюда не попадают: у них метод пустой).
        $methods = ['cash', 'card', 'kaspi', 'certificate', 'deposit', 'cashback', 'cashless'];
        $sums = array_fill_keys($methods, 0.0);
        $paid = CourtBooking::whereIn('court_id', $courtIds)
            ->where('status', 'confirmed')
            ->where('is_paid', true)
            ->whereIn('payment_method', $methods)
            ->whereDate('date', '>=', $fromD)
            ->whereDate('date', '<=', $toD)
            ->get(['payment_method', 'price', 'discount']);
        foreach ($paid as $b) {
            $sums[$b->payment_method] += (float) $b->price - (float) $b->discount;
        }

        // 2) Клубная карта — списанные часы × (цена карты / номинал).
        $clubCard = 0.0;
        $cardBookings = CourtBooking::whereIn('court_id', $courtIds)
            ->where('status', 'confirmed')
            ->whereNotNull('club_card_id')
            ->whereNotNull('card_charged_at')
            ->whereDate('date', '>=', $fromD)
            ->whereDate('date', '<=', $toD)
            ->with('clubCard.type')
            ->get();
        foreach ($cardBookings as $b) {
            $type = $b->clubCard?->type;
            if (!$type || !$type->isCounter()) continue;      // только счётчики
            $nominal = (int) $type->nominal;
            $price = (float) $type->price;
            if ($nominal <= 0 || $price <= 0) continue;        // нечем считать цену часа
            $clubCard += $this->bookingHours($b) * ($price / $nominal);
        }

        // 3) Групповые — проведённые занятия × списанные участники × цена занятия.
        $group = 0.0;
        $sessions = ClubGroupSession::whereIn('court_id', $courtIds)
            ->where('status', 'held')
            ->whereDate('date', '>=', $fromD)
            ->whereDate('date', '<=', $toD)
            ->withCount(['attendance as charged_count' => fn($q) => $q->where('charged', true)])
            ->with('group:id,price_per_session')
            ->get();
        foreach ($sessions as $s) {
            $group += (int) $s->charged_count * (float) ($s->group->price_per_session ?? 0);
        }

        // 4) Выплаты тренерам (расход) — за групповые и за индивидуальные.
        $payouts = app(CoachesReportService::class)->payoutTotals($club, $from, $to);
        $coachGroup = (float) $payouts['group'];
        $coachInd = (float) $payouts['individual'];

        $incomeRows = [
            ['Групповые',     round($group)],
            ['Наличные',      round($sums['cash'])],
            ['Карта',         round($sums['card'])],
            ['Kaspi',         round($sums['kaspi'])],
            ['Сертификат',    round($sums['certificate'])],
            ['Клубная карта', round($clubCard)],
            ['Депозит',       round($sums['deposit'])],
            ['Кешбэк',        round($sums['cashback'])],
            ['Безналичный',   round($sums['cashless'])],
        ];
        $incomeTotal = array_sum(array_map(fn($r) => $r[1], $incomeRows));
        $payoutTotal = round($coachGroup) + round($coachInd);

        $rows = array_merge(
            $incomeRows,
            [
                ['Итого доходов', round($incomeTotal)],
                ['', ''],
                ['Выплаты тренерам — за групповые', round($coachGroup)],
                ['Выплаты тренерам — за индивидуальные', round($coachInd)],
                ['Итого выплат тренерам', round($payoutTotal)],
            ]
        );

        return new ReportSheet(
            title: 'Доходы клуба в разрезе',
            headings: ['Категория', 'Сумма, ₸'],
            rows: $rows,
            totals: ['Доход − выплаты тренерам', round($incomeTotal - $payoutTotal)],
            columnFormats: [1 => '#,##0'],
        );
    }
}
