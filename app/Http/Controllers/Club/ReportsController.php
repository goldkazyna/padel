<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\User;
use App\Support\PhoneVisibility;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    /** Определяем клуб для текущего пользователя. */
    private function getClub(): ?Club
    {
        $user = auth()->user();
        if (!$user) return null;
        if ($user->isSuperAdmin()) return Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    /** Парсим период из запроса. Возвращаем [from, to, label]. */
    private function parsePeriod(Request $request): array
    {
        $preset = $request->get('preset');
        $now = Carbon::today();

        switch ($preset) {
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

        // Custom / default
        $from = $request->filled('from')
            ? Carbon::parse($request->get('from'))
            : $now->copy()->subDays(29);
        $to = $request->filled('to')
            ? Carbon::parse($request->get('to'))
            : $now->copy();
        return [$from, $to, $from->format('d.m.Y') . ' — ' . $to->format('d.m.Y')];
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        [$from, $to, $periodLabel] = $this->parsePeriod($request);

        $data = $this->compute($club, $from, $to);
        // Для сравнения — тот же интервал перед from
        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);
        $prev = $this->compute($club, $prevFrom, $prevTo);

        // Неразобранные — брони без указанного телефона
        $noPhoneTotal = $this->noPhoneBookingsQuery($club)->count();

        return view('club.reports.index', [
            'club' => $club,
            'from' => $from,
            'to' => $to,
            'periodLabel' => $periodLabel,
            'preset' => $request->get('preset'),
            'data' => $data,
            'prev' => $prev,
            'noPhoneTotal' => $noPhoneTotal,
        ]);
    }

    /**
     * Базовый запрос броней клуба без указанного телефона.
     */
    private function noPhoneBookingsQuery(Club $club)
    {
        $courtIds = $club->courts()->pluck('id');
        return CourtBooking::whereIn('court_id', $courtIds)
            ->where('status', 'confirmed')
            ->where(function ($q) {
                $q->whereNull('client_phone')->orWhere('client_phone', '');
            });
    }

    /**
     * Страница «Неразобранные брони» — брони без телефона клиента.
     * GET /club/reports/no-phone
     */
    public function noPhoneBookings(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $bookings = $this->noPhoneBookingsQuery($club)
            ->with('court')
            ->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        return view('club.reports.no_phone', [
            'club' => $club,
            'bookings' => $bookings,
            'totalCount' => $bookings->count(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        [$from, $to] = $this->parsePeriod($request);

        $courtIds = $club->courts()->pluck('id');
        $bookings = CourtBooking::whereIn('court_id', $courtIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with(['court', 'coach', 'bookedByUser'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $fileName = 'bookings_' . $from->format('Y-m-d') . '_' . $to->format('Y-m-d') . '.csv';

        $callback = function () use ($bookings) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM чтобы Excel корректно отображал кириллицу
            fwrite($out, "\xEF\xBB\xBF");
            // Заголовок (; разделитель для Excel RU)
            fputcsv($out, [
                'Дата', 'Время', 'Корт', 'Клиент', 'Телефон',
                'Статус', 'Обработано', 'Оплата', 'Оплачено',
                'Сумма', 'Скидка', 'Тренер', 'Источник', 'Комментарий',
            ], ';');

            foreach ($bookings as $b) {
                $source = $this->sourceOf($b->bookedByUser);
                $status = match ($b->status) {
                    'confirmed' => 'Подтверждено',
                    'cancelled' => 'Отменено',
                    default => $b->status,
                };
                $payment = match ($b->payment_method) {
                    'cash' => 'Наличные',
                    'card' => 'Карта',
                    'kaspi' => 'Kaspi',
                    'certificate' => 'Сертификат',
                    'club_card' => 'Клубная карта',
                    'deposit' => 'Депозит',
                    'cashback' => 'Кэшбэк',
                    null, '' => '—',
                    default => $b->payment_method,
                };

                fputcsv($out, [
                    Carbon::parse($b->date)->format('d.m.Y'),
                    substr($b->start_time, 0, 5) . '–' . substr($b->end_time, 0, 5),
                    $b->court->name ?? '',
                    $b->client_name,
                    PhoneVisibility::forExport($b->client_phone),
                    $status,
                    $b->is_processed ? 'да' : 'нет',
                    $payment,
                    $b->is_paid ? 'да' : 'нет',
                    number_format((float) $b->price, 0, '.', ''),
                    number_format((float) ($b->discount ?? 0), 0, '.', ''),
                    $b->coach?->full_name ?? $b->coach?->name ?? '',
                    $source,
                    $b->comment ?? '',
                ], ';');
            }
            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    private function sourceOf(?User $user): string
    {
        if (!$user) return 'Админ';
        return $user->role === 'player' ? 'Приложение' : 'Админ';
    }

    /**
     * Считаем все метрики за период.
     */
    private function compute(Club $club, Carbon $from, Carbon $to): array
    {
        $courts = $club->courts()->where('is_active', true)->get();
        $courtIds = $courts->pluck('id');
        $daysInPeriod = $from->diffInDays($to) + 1;

        $all = CourtBooking::whereIn('court_id', $courtIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with(['court', 'coach', 'bookedByUser'])
            ->get();

        $confirmed = $all->where('status', 'confirmed');
        $cancelled = $all->where('status', 'cancelled');

        $revenue = (float) $confirmed->sum('price');
        $totalCount = $confirmed->count();
        $paidCount = $confirmed->where('is_paid', true)->count();
        $avgCheck = $totalCount > 0 ? $revenue / $totalCount : 0.0;

        // Занятые слоты в минутах = сумма длительностей
        $bookedMinutes = 0;
        foreach ($confirmed as $b) {
            $s = Carbon::parse($b->start_time);
            $e = Carbon::parse($b->end_time);
            $bookedMinutes += $s->diffInMinutes($e);
        }
        // Доступные минуты = кортов × 24ч × дней
        $availableMinutes = $courts->count() * 24 * 60 * $daysInPeriod;
        $occupancy = $availableMinutes > 0
            ? round(($bookedMinutes / $availableMinutes) * 100, 1)
            : 0.0;

        // --- Revenue by day ---
        $byDayMap = [];
        foreach ($confirmed as $b) {
            $d = Carbon::parse($b->date)->format('Y-m-d');
            $byDayMap[$d] = ($byDayMap[$d] ?? 0) + (float) $b->price;
        }
        $revenueByDay = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $revenueByDay[] = [
                'date' => $d->format('d.m'),
                'value' => $byDayMap[$key] ?? 0,
            ];
        }

        // --- Occupancy by hour (0-23) ---
        $byHour = array_fill(0, 24, 0);
        foreach ($confirmed as $b) {
            $h = (int) Carbon::parse($b->start_time)->format('H');
            $byHour[$h]++;
        }
        $hourMax = max($byHour) ?: 1;
        $occupancyByHour = [];
        for ($h = 0; $h < 24; $h++) {
            $occupancyByHour[] = ['hour' => sprintf('%02d', $h), 'count' => $byHour[$h]];
        }

        // --- By day of week (Mon=1..Sun=7) ---
        $dowMap = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
        foreach ($confirmed as $b) {
            $dow = Carbon::parse($b->date)->dayOfWeekIso;
            $dowMap[$dow]++;
        }
        $dowLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $byDayOfWeek = [];
        for ($i = 1; $i <= 7; $i++) {
            $byDayOfWeek[] = ['label' => $dowLabels[$i - 1], 'count' => $dowMap[$i]];
        }

        // --- By court (revenue) ---
        $byCourt = [];
        foreach ($courts as $c) {
            $sum = (float) $confirmed->where('court_id', $c->id)->sum('price');
            if ($sum > 0) {
                $byCourt[] = ['name' => $c->name, 'value' => $sum];
            }
        }

        // --- Source (app vs admin) ---
        $appCount = 0;
        $adminCount = 0;
        foreach ($confirmed as $b) {
            if ($b->bookedByUser && $b->bookedByUser->role === 'player') {
                $appCount++;
            } else {
                $adminCount++;
            }
        }

        // --- Payment methods ---
        $paymentMap = [];
        foreach ($confirmed as $b) {
            $m = $b->payment_method ?: 'unset';
            $paymentMap[$m] = ($paymentMap[$m] ?? 0) + 1;
        }
        $paymentLabels = [
            'cash' => 'Наличные',
            'card' => 'Карта',
            'kaspi' => 'Kaspi',
            'certificate' => 'Сертификат',
            'club_card' => 'Клубная карта',
            'deposit' => 'Депозит',
            'cashback' => 'Кэшбэк',
            'unset' => 'Не указан',
        ];
        $byPayment = [];
        foreach ($paymentMap as $key => $count) {
            $byPayment[] = ['label' => $paymentLabels[$key] ?? $key, 'count' => $count];
        }

        // --- Top clients ---
        $clientMap = [];
        foreach ($confirmed as $b) {
            $key = $b->client_phone ?: $b->client_name;
            if (!isset($clientMap[$key])) {
                $clientMap[$key] = [
                    'name' => $b->client_name,
                    'phone' => $b->client_phone,
                    'count' => 0,
                    'sum' => 0.0,
                ];
            }
            $clientMap[$key]['count']++;
            $clientMap[$key]['sum'] += (float) $b->price;
        }
        usort($clientMap, fn($a, $b) => $b['sum'] <=> $a['sum']);
        $topClients = array_slice($clientMap, 0, 10);

        // --- Coach revenue ---
        $coachMap = [];
        foreach ($confirmed as $b) {
            if (!$b->coach_id) continue;
            $key = $b->coach_id;
            if (!isset($coachMap[$key])) {
                $coachMap[$key] = [
                    'name' => $b->coach?->full_name ?? $b->coach?->name ?? '#' . $key,
                    'count' => 0,
                ];
            }
            $coachMap[$key]['count']++;
        }
        usort($coachMap, fn($a, $b) => $b['count'] <=> $a['count']);
        $byCoach = array_values($coachMap);

        return [
            'revenue' => $revenue,
            'count' => $totalCount,
            'paid' => $paidCount,
            'cancelled' => $cancelled->count(),
            'occupancy' => $occupancy,
            'avg_check' => $avgCheck,
            'revenue_by_day' => $revenueByDay,
            'occupancy_by_hour' => $occupancyByHour,
            'by_day_of_week' => $byDayOfWeek,
            'by_court' => $byCourt,
            'source_app' => $appCount,
            'source_admin' => $adminCount,
            'by_payment' => $byPayment,
            'top_clients' => $topClients,
            'by_coach' => $byCoach,
        ];
    }
}
