<?php

namespace App\Reports;

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Отменённые брони: когда, кто и почему снял корт.
 *
 * Отмены раньше нигде не собирались: причину человек пишет при отмене, она
 * ложится в саму бронь, а кто нажал — знает только журнал действий. Отчёт
 * сводит это вместе, чтобы было видно, кто и с какими объяснениями
 * освобождает корты.
 */
class CancelledBookingsReportService
{
    use CalculatesBookingRevenue;

    public function list(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'cancelled')
            ->with(['court', 'coach'])
            // Период — по дате отмены: вопрос отчёта «кто отменял на этой
            // неделе», а не «какие из отменённых стояли на эту неделю».
            // У старых записей времени отмены нет — тогда по дате брони.
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('cancelled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                    ->orWhere(function ($q2) use ($from, $to) {
                        $q2->whereNull('cancelled_at')
                            ->whereDate('date', '>=', $from->toDateString())
                            ->whereDate('date', '<=', $to->toDateString());
                    });
            })
            ->orderByDesc('cancelled_at')
            ->orderByDesc('date')
            ->get();

        $actors = $this->actors($bookings->pluck('id')->all());

        $rows = [];
        $total = 0.0;

        foreach ($bookings as $b) {
            $amount = $this->bookingRevenue($b, $club->id);
            $total += $amount;

            $actor = $actors[$b->id] ?? null;

            $rows[] = [
                $b->cancelled_at?->format('d.m.Y H:i') ?? '',
                $actor['name'] ?? '',
                $actor['source'] ?? '',
                Carbon::parse($b->date)->format('d.m.Y'),
                Carbon::parse($b->start_time)->format('H:i')
                    . '–' . Carbon::parse($b->end_time)->format('H:i'),
                $b->court->name ?? '',
                $b->client_name ?: '',
                $b->client_phone ?: '',
                round($amount, 2),
                $b->cancel_reason ?: '',
            ];
        }

        return new ReportSheet(
            title: 'Отменённые брони',
            headings: [
                'Отменена', 'Кто отменил', 'Откуда', 'Дата брони', 'Время',
                'Корт', 'Клиент', 'Телефон', 'Сумма', 'Причина',
            ],
            rows: $rows,
            totals: ['Итого', count($rows) . ' шт.', '', '', '', '', '', '', round($total, 2), ''],
            columnFormats: [7 => '@', 8 => '#,##0'],
        );
    }

    /**
     * Кто снял бронь — из журнала действий: в самой брони этого не хранится.
     *
     * Берём последнюю запись отмены по каждой броне; по тексту видно, откуда
     * пришла отмена — из приложения или из расписания клуба.
     *
     * @param  array<int,int>  $bookingIds
     * @return array<int,array{name:string,source:string}>
     */
    private function actors(array $bookingIds): array
    {
        if (empty($bookingIds)) {
            return [];
        }

        $logs = ActivityLog::where('subject_type', 'CourtBooking')
            ->where('action', 'cancelled')
            ->whereIn('subject_id', $bookingIds)
            ->orderBy('id')
            ->get(['user_id', 'subject_id', 'description']);

        $names = User::whereIn('id', $logs->pluck('user_id')->filter()->unique())
            ->pluck('name', 'id');

        $out = [];
        foreach ($logs as $log) {
            $out[(int) $log->subject_id] = [
                'name' => $names[$log->user_id] ?? 'Не указан',
                'source' => str_contains((string) $log->description, 'из приложения')
                    ? 'Приложение'
                    : 'Клуб',
            ];
        }

        return $out;
    }
}
