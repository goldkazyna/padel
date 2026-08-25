<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use App\Models\Club;
use App\Reports\ClubIncomeReportService;
use App\Reports\ClubLoadReportService;
use App\Reports\ClientsReportService;
use App\Reports\CoachesReportService;
use App\Reports\FinanceReportService;
use App\Reports\ManagersReportService;
use App\Reports\CardsReportService;
use App\Exports\GenericSheetExport;
use App\Support\ResolvesReportPeriod;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AdditionalReportsController extends Controller
{
    use ResolvesReportPeriod;

    /** slug => [serviceClass, method, filenameBase, categoryLabel, reportLabel] */
    private const REPORTS = [
        'income-breakdown' => [ClubIncomeReportService::class, 'breakdown', 'dohody-v-razreze',     'Доходы', 'Доходы клуба в разрезе'],
        'club-hours'       => [ClubLoadReportService::class, 'byHours',   'zagruzka-po-chasam',     'Клуб', 'Загруженность по часам'],
        'club-weekdays'    => [ClubLoadReportService::class, 'byWeekdays','zagruzka-po-dnyam',      'Клуб', 'Загруженность по дням недели'],
        'club-months'      => [ClubLoadReportService::class, 'byMonths',  'zagruzka-po-mesyacam',   'Клуб', 'Загруженность по месяцам'],
        'clients-visits'   => [ClientsReportService::class,  'visits',    'poseshcheniya',          'Клиенты', 'Посещения клиентов'],
        'coaches-usage'    => [CoachesReportService::class,  'usage',     'trenery-ispolzovanie',   'Тренеры', 'Использование услуг'],
        'coaches-sessions' => [CoachesReportService::class,  'sessions',  'trenery-trenirovki',     'Тренеры', 'Проведённые тренировки'],
        'coaches-salary'   => [CoachesReportService::class,  'salary',    'trenery-zarplata',       'Тренеры', 'Зарплата тренеров'],
        'coaches-income-type' => [CoachesReportService::class, 'incomeByType', 'trenery-dohod-po-tipam', 'Тренеры', 'Доход по типам'],
        'coaches-unpaid'   => [CoachesReportService::class,  'unpaid',    'trenery-neoplacheno',    'Тренеры', 'Неоплаченные тренеры'],
        'finance-sales'    => [FinanceReportService::class,  'sales',     'prodazhi',               'Финансы', 'Продажи'],
        'finance-days'     => [FinanceReportService::class,  'byDays',    'prodazhi-po-dnyam',      'Финансы', 'Продажи по дням'],
        'finance-weeks'    => [FinanceReportService::class,  'byWeeks',   'prodazhi-po-nedelyam',   'Финансы', 'Продажи по неделям'],
        'finance-months'   => [FinanceReportService::class,  'byMonths',  'prodazhi-po-mesyacam',   'Финансы', 'Продажи по месяцам'],
        'finance-debts'    => [FinanceReportService::class,  'debts',     'zadolzhennosti',         'Финансы', 'Задолженности'],
        'managers-sales'   => [ManagersReportService::class, 'sales',     'menedzhery-prodazhi',    'Менеджеры', 'Аналитика продаж менеджеров'],
        'cards-sales'      => [CardsReportService::class,    'sales',     'karty-prodazhi',         'Клубные карты', 'Продажи карт'],
        'cards-charges'    => [CardsReportService::class,    'charges',   'karty-spisaniya',        'Клубные карты', 'Списания часов'],
    ];

    private function getClub(): ?Club
    {
        $user = auth()->user();
        if (!$user) return null;
        if ($user->isSuperAdmin()) return Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);
        [$from, $to, $periodLabel] = $this->parsePeriod($request);

        $grouped = [];
        foreach (self::REPORTS as $slug => [$svc, $method, $file, $category, $label]) {
            $grouped[$category][] = ['slug' => $slug, 'label' => $label];
        }

        return view('club.reports.extra', [
            'club' => $club,
            'from' => $from,
            'to' => $to,
            'periodLabel' => $periodLabel,
            'preset' => $request->get('preset'),
            'grouped' => $grouped,
        ]);
    }

    /**
     * Задолженности в разрезе одного клиента.
     * GET /club/reports/debts-by-client
     *
     * Общий отчёт отвечает на вопрос «сколько нам должны», а этот — «за что
     * должен вот этот человек»: с датами, временем и кортом, чтобы было что
     * показать при разговоре. Печатается из браузера в PDF.
     *
     * Период по умолчанию не ставим: долг не перестаёт быть долгом оттого,
     * что бронь была в прошлом месяце.
     */
    public function debtsByClient(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $from = $request->filled('from') ? Carbon::parse($request->get('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->get('to'))->endOfDay() : null;

        $query = \App\Models\CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->where('is_paid', false)
            ->with(['court', 'coach'])
            ->orderBy('date')->orderBy('start_time');

        if ($from) $query->whereDate('date', '>=', $from->toDateString());
        if ($to) $query->whereDate('date', '<=', $to->toDateString());

        $revenue = app(\App\Reports\FinanceReportService::class);
        $bookings = $query->get();

        // Клиенты группируются по последним 10 цифрам номера: в бронях он
        // записан как придётся, а долг у человека один.
        $clients = [];
        foreach ($bookings as $b) {
            $key = $this->debtorKey($b);
            $amount = (float) $revenue->amountOf($b, $club->id);
            if ($amount <= 0) continue;

            $clients[$key] ??= [
                'key' => $key,
                'name' => $b->client_name ?: 'Без имени',
                'phone' => $b->client_phone,
                'total' => 0.0,
                'count' => 0,
                'bookings' => [],
            ];
            $clients[$key]['total'] += $amount;
            $clients[$key]['count']++;
            $clients[$key]['bookings'][] = ['booking' => $b, 'amount' => $amount];
        }

        uasort($clients, fn ($a, $b) => $b['total'] <=> $a['total']);

        $selected = $request->get('client');
        $current = $selected !== null ? ($clients[$selected] ?? null) : null;

        // Разбивка выбранного клиента по месяцам: так видно, когда долг рос.
        $months = [];
        if ($current) {
            foreach ($current['bookings'] as $row) {
                $date = Carbon::parse($row['booking']->date);
                $key = $date->format('Y-m');
                $months[$key] ??= ['label' => $date->locale('ru')->translatedFormat('F Y'),
                                   'total' => 0.0, 'rows' => []];
                $months[$key]['total'] += $row['amount'];
                $months[$key]['rows'][] = $row;
            }
            ksort($months);
        }

        return view('club.reports.debts_by_client', [
            'club' => $club,
            'clients' => array_values($clients),
            'current' => $current,
            'months' => $months,
            'from' => $from,
            'to' => $to,
            'totalDebt' => array_sum(array_column($clients, 'total')),
        ]);
    }

    /** Ключ должника: последние 10 цифр номера, иначе имя. */
    private function debtorKey(\App\Models\CourtBooking $booking): string
    {
        $digits = preg_replace('/\D/', '', (string) $booking->client_phone);

        return strlen($digits) >= 10
            ? substr($digits, -10)
            : 'name:' . mb_strtolower(trim((string) $booking->client_name));
    }

    public function download(Request $request, string $report)
    {
        $club = $this->getClub();
        if (!$club) abort(403);
        if (!isset(self::REPORTS[$report])) abort(404);

        [$serviceClass, $method, $fileBase] = self::REPORTS[$report];
        [$from, $to] = $this->parsePeriod($request);

        $sheet = app($serviceClass)->{$method}($club, $from, $to);
        $filename = $fileBase . '_' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d') . '.xlsx';

        return Excel::download(new GenericSheetExport($sheet), $filename);
    }
}
