<?php

namespace App\Services;

use App\Models\Shift;
use App\Reports\FinanceReportService;
use App\Support\ClubTime;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

/**
 * PDF-отчёт по оплаченным броням в Telegram после закрытия смены.
 *
 * Владельцу не нужно заходить в CRM, чтобы узнать, чем закончился день: бот
 * присылает тот же отчёт, что скачивается на странице отчётов.
 */
class ShiftReportSender
{
    public function __construct(private FinanceReportService $finance)
    {
    }

    /**
     * Отправить отчёт за день закрытой смены.
     *
     * День берём по времени открытия смены, а не по «сегодня»: смена может
     * закрываться после полуночи, и отчёт должен быть за отработанный день.
     */
    public function sendForShift(Shift $shift): void
    {
        $club = $shift->club;

        if (!$club || !$club->shift_report_enabled || !$club->telegramNotifyReady()) {
            return;
        }

        $day = $shift->openedAtLocal()->copy()->startOfDay();

        try {
            $sheet = $this->finance->paidBookings($club, $day->copy(), $day->copy()->endOfDay());

            $pdf = Pdf::loadView('club.reports.pdf', [
                'sheet' => $sheet,
                'club' => $club,
                'from' => $day,
                'to' => $day,
                'generatedAt' => ClubTime::now()->format('d.m.Y H:i'),
            ])->setPaper('a4', 'landscape');

            // Строки после пустой — свод по менеджерам, в «продажах» их не считаем.
            $sales = 0;
            foreach ($sheet->rows as $row) {
                if (($row[0] ?? '') === '') {
                    break;
                }
                $sales++;
            }

            $total = (float) ($sheet->totals[5] ?? 0);
            $caption = '💰 <b>Оплаченные брони за ' . $day->format('d.m.Y') . '</b>' . "\n"
                . e($club->name) . "\n"
                . 'Продаж: ' . $sales . ' · на сумму ' . number_format($total, 0, ',', ' ') . ' ₸';

            ClubTelegramNotifier::sendDocument(
                $club,
                'otchet_' . $day->format('Y-m-d') . '.pdf',
                $pdf->output(),
                $caption,
            );
        } catch (\Throwable $e) {
            // Отчёт не должен ронять закрытие смены: менеджер уже всё отметил.
            Log::warning('ShiftReportSender: отчёт не отправлен', [
                'club' => $club->id, 'shift' => $shift->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
