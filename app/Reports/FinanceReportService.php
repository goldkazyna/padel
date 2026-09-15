<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\User;
use Carbon\Carbon;

class FinanceReportService
{
    use CalculatesBookingRevenue;

    /** Способы оплаты по-русски: в выгрузке «kaspi» читается как мусор. */
    private const PAYMENT_LABELS = [
        'cash' => 'Наличные',
        'card' => 'Карта',
        'kaspi' => 'Kaspi',
        'plexy' => 'Онлайн (Plexy)',
        'certificate' => 'Сертификат',
        'club_card' => 'Клубная карта',
        'deposit' => 'Депозит',
        'cashback' => 'Кэшбэк',
        'cashless' => 'Безналичный',
        'free' => 'Бесплатно',
    ];

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

    /**
     * Сумма одной брони — цена корта минус скидка плюс тренер.
     *
     * Нужна снаружи: страница «Задолженности по клиенту» считает те же
     * деньги, что и отчёт, и расходиться с ним не должна.
     */
    public function amountOf($booking, int $clubId): float
    {
        return $this->bookingRevenue($booking, $clubId);
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
                // Время нужно, чтобы отличать две брони клиента в один день.
                Carbon::parse($b->start_time)->format('H:i')
                    . '–' . Carbon::parse($b->end_time)->format('H:i'),
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
            headings: ['Дата', 'Время', 'Корт', 'Клиент', 'Телефон', 'Сумма', 'Менеджер'],
            rows: $rows,
            totals: ['Итого', '', '', '', '', round($tDebt, 2), ''],
            columnFormats: [4 => '@', 5 => '#,##0'],
        );
    }

    /**
     * Выручка: оплаченные брони и проданные клубные карты — с продавцом.
     *
     * Что сюда не идёт:
     * - групповые и турнирные брони: за группу платят пакетами участников, за
     *   турнир — взносами, в кассе этих денег не было;
     * - брони, оплаченные клубной картой: деньги за них клуб получил в момент
     *   продажи карты, и она в отчёте уже есть — иначе одна сумма считалась бы
     *   дважды.
     *
     * Продавец берётся из журнала действий, а не из booked_by: в booked_by
     * лежит игрок, за которым бронь (для брони на клиента с аккаунтом это сам
     * клиент), поэтому в «менеджерах» оказывались клиенты. В журнале же
     * записан тот, кто действие совершил.
     *
     * Кого считаем продавцом: сотрудника, который отметил бронь оплаченной,
     * иначе — сотрудника, который её завёл в CRM. Бронь, сделанную клиентом в
     * приложении и оплаченную онлайн, никто из клуба не оформлял — она идёт
     * на «Приложение».
     *
     * Под таблицей — свод: кто сколько продал.
     */
    public function paidBookings(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $names = [];
        $entries = [];
        $staff = $this->staffIds($club);

        $bookings = $this->confirmed($club, $from, $to)
            ->where('is_paid', true)
            ->filter(fn ($b) => !in_array($b->booking_type, ['group', 'tournament'], true))
            ->filter(fn ($b) => $b->payment_method !== 'club_card');

        $sellers = $this->bookingSellers($bookings->pluck('id')->all(), $staff, $names);

        foreach ($bookings as $b) {
            $entries[] = [
                'date' => $this->parseDate($b->date),
                'amount' => $this->bookingRevenue($b, $club->id),
                'seller' => $sellers[(int) $b->id] ?? ($b->payment_method === 'plexy' ? 'Приложение' : 'Не указан'),
                'row' => [
                    $this->parseDate($b->date)->format('d.m.Y'),
                    Carbon::parse($b->start_time)->format('H:i')
                        . '–' . Carbon::parse($b->end_time)->format('H:i'),
                    $b->court->name ?? '',
                    $b->client_name ?? '',
                    $b->client_phone ?? '',
                    round($this->bookingRevenue($b, $club->id), 2),
                    round((float) $b->discount, 2),
                    self::PAYMENT_LABELS[$b->payment_method ?? ''] ?? ($b->payment_method ?: 'Не указан'),
                    (string) ($b->transaction_number ?? ''),
                    '',
                ],
            ];
        }

        foreach ($this->cardSales($club, $from, $to) as $card) {
            $price = (float) ($card->type->price ?? 0);
            $issued = $this->parseDate($card->created_at);
            $entries[] = [
                'date' => $issued,
                'amount' => $price,
                'seller' => $card->issued_by !== null && isset($staff[(int) $card->issued_by])
                    ? ($this->managerName((int) $card->issued_by, $names) ?: 'Не указан')
                    : 'Не указан',
                'row' => [
                    $issued->format('d.m.Y'),
                    $issued->format('H:i'),
                    'Карта «' . ($card->type->name ?? '—') . '»',
                    $card->client->name ?? '—',
                    $card->client->phone ?? '',
                    round($price, 2),
                    0,
                    'Продажа карты',
                    (string) $card->code,
                    '',
                ],
            ];
        }

        // Брони и карты идут одним списком по времени: так читается как лента
        // продаж за период, а не как две несвязанные таблицы.
        usort($entries, fn ($a, $b) => $a['date'] <=> $b['date']);

        $rows = [];
        $bySeller = [];
        $total = 0.0;
        $totalDiscount = 0.0;

        foreach ($entries as $entry) {
            $row = $entry['row'];
            $row[9] = $entry['seller'];
            $rows[] = $row;

            $bySeller[$entry['seller']] ??= ['count' => 0, 'sum' => 0.0];
            $bySeller[$entry['seller']]['count']++;
            $bySeller[$entry['seller']]['sum'] += $entry['amount'];
            $total += $entry['amount'];
            $totalDiscount += (float) $row[6];
        }

        $totals = ['Итого', '', '', '', '', round($total, 2), round($totalDiscount, 2), '', '', ''];

        // Свод по продавцам — отдельным блоком под таблицей, жирными строками:
        // в одном отчёте видно и каждую продажу, и кто сколько сделал.
        $boldRows = [];
        if ($bySeller) {
            uasort($bySeller, fn ($a, $b) => $b['sum'] <=> $a['sum']);
            $rows[] = array_fill(0, 10, '');
            $boldRows[] = count($rows);
            $rows[] = ['По менеджерам', '', '', '', '', 'Сумма', 'Продаж', '', '', ''];
            foreach ($bySeller as $seller => $data) {
                $rows[] = [$seller, '', '', '', '', round($data['sum'], 2), $data['count'], '', '', ''];
            }
        }

        return new ReportSheet(
            title: 'Оплаченные брони и карты',
            headings: ['Дата', 'Время', 'Корт / карта', 'Клиент', 'Телефон', 'Сумма', 'Скидка', 'Оплата', '№ транзакции / карты', 'Менеджер'],
            rows: $rows,
            totals: $totals,
            columnFormats: [4 => '@', 5 => '#,##0', 6 => '#,##0', 8 => '@'],
            boldRows: $boldRows ?: null,
        );
    }

    /**
     * Продавец по каждой брони — из журнала действий клуба.
     *
     * Приоритет: кто отметил оплату → кто завёл бронь в CRM → «Приложение».
     * Оплату через Plexy подтверждает сам шлюз, в журнале её нет, и такая
     * бронь остаётся за приложением.
     *
     * @param  array<int, int>  $bookingIds
     * @param  array<int, true> $staff
     * @return array<int, string>  booking_id => продавец
     */
    private function bookingSellers(array $bookingIds, array $staff, array &$names): array
    {
        if (empty($bookingIds)) {
            return [];
        }

        $logs = \App\Models\ActivityLog::where('subject_type', 'CourtBooking')
            ->whereIn('subject_id', $bookingIds)
            ->whereIn('action', ['created', 'updated'])
            ->orderBy('id')
            ->get(['user_id', 'subject_id', 'action', 'description', 'changes']);

        $createdBy = [];
        $paidBy = [];

        foreach ($logs as $log) {
            $bookingId = (int) $log->subject_id;
            $userId = $log->user_id ? (int) $log->user_id : null;

            if ($log->action === 'created') {
                // Бронь из приложения делает клиент — сотрудником он не является,
                // но и в «Не указан» её сваливать не надо.
                $createdBy[$bookingId] = str_contains((string) $log->description, 'из приложения')
                    ? null
                    : $userId;
                continue;
            }

            // Правка: интересует только момент, когда бронь стала оплаченной.
            $changes = $log->changes;
            if (is_array($changes) && array_key_exists('is_paid', $changes) && (bool) $changes['is_paid']) {
                $paidBy[$bookingId] = $userId;
            }
        }

        $out = [];
        foreach ($bookingIds as $id) {
            $id = (int) $id;
            $userId = $paidBy[$id] ?? $createdBy[$id] ?? null;

            $out[$id] = $userId !== null && isset($staff[$userId])
                ? ($this->managerName($userId, $names) ?: 'Не указан')
                : 'Приложение';
        }

        return $out;
    }

    /**
     * Сотрудники клуба: администраторы и менеджеры.
     *
     * @return array<int, true>
     */
    private function staffIds(Club $club): array
    {
        return $club->admins()->pluck('users.id')
            ->merge($club->moderators()->pluck('users.id'))
            ->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Карты, привязанные клиентам за период, — каждая привязка это продажа.
     *
     * Карты без клиента не берём: непривязанная карта никому не продана.
     */
    private function cardSales(Club $club, Carbon $from, Carbon $to)
    {
        return \App\Models\ClubCard::with(['client', 'type'])
            ->where('club_id', $club->id)
            ->whereNotNull('club_client_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('created_at')
            ->get();
    }
}
