<?php

namespace App\Console\Commands;

use App\Models\CourtBooking;
use App\Services\PlexyService;
use Illuminate\Console\Command;

/**
 * Сверка зависших платежей с Plexy.
 *
 * Бронь в статусе pending может означать две разные вещи: человек открыл
 * ссылку и не заплатил (брошенная корзина) либо заплатил, а подтверждение
 * до нас не дошло — вебхук потерялся или был отклонён. Отличить их можно
 * только спросив сам шлюз по каждой ссылке.
 *
 *   php artisan payments:reconcile-plexy              # только показать
 *   php artisan payments:reconcile-plexy --apply      # пометить оплаченные
 *   php artisan payments:reconcile-plexy --days=90    # глубина поиска
 */
class ReconcilePlexyPayments extends Command
{
    protected $signature = 'payments:reconcile-plexy {--apply} {--days=60}';
    protected $description = 'Сверить неоплаченные брони с Plexy: найти оплаты, о которых мы не узнали';

    /** Статусы шлюза, означающие «деньги получены». */
    private const PAID_STATUSES = ['paid', 'charged', 'authorized', 'success', 'completed'];

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $apply = (bool) $this->option('apply');

        $bookings = CourtBooking::query()
            ->whereNotNull('payment_id')
            ->where('is_paid', false)
            ->where('created_at', '>=', now()->subDays($days))
            ->with('court.club')
            ->orderBy('created_at')
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('Зависших платежей за ' . $days . ' дн. нет.');
            return self::SUCCESS;
        }

        $this->info('Проверяю ' . $bookings->count() . ' броней в Plexy…');

        $rows = [];
        $lost = [];
        $errors = 0;

        foreach ($bookings as $booking) {
            $club = $booking->court?->club;
            $key = $club?->plexyApiKey();

            if (!$key) {
                $rows[] = [$booking->id, $booking->price, '—', 'нет ключа клуба'];
                $errors++;
                continue;
            }

            try {
                $info = (new PlexyService($key))->getPaymentLink($booking->payment_id);
            } catch (\Throwable $e) {
                // Одна битая ссылка не должна прерывать сверку остальных.
                $rows[] = [$booking->id, $booking->price, '—', 'ошибка: ' . mb_substr($e->getMessage(), 0, 40)];
                $errors++;
                continue;
            }

            $status = strtolower((string) ($info['status'] ?? ''));
            $isPaid = in_array($status, self::PAID_STATUSES, true);

            if ($isPaid) {
                $lost[] = $booking;
                $rows[] = [$booking->id, $booking->price, $status, 'ОПЛАЧЕНО — мы не знали'];
            } else {
                $rows[] = [$booking->id, $booking->price, $status, 'не оплачено'];
            }
        }

        $this->table(['Бронь', 'Сумма', 'Статус в Plexy', 'Итог'], $rows);

        $lostSum = collect($lost)->sum(fn ($b) => (float) $b->price);
        $this->newLine();
        $this->info('Потерянных оплат: ' . count($lost) . ' на сумму ' . number_format($lostSum, 0, '.', ' ') . ' ₸');
        if ($errors) {
            $this->warn('Не удалось проверить: ' . $errors);
        }

        if (!$apply) {
            if ($lost) {
                $this->comment('Это прогон без изменений. Чтобы пометить их оплаченными: --apply');
            }
            return self::SUCCESS;
        }

        foreach ($lost as $booking) {
            $booking->update([
                'is_paid' => true,
                'payment_status' => 'paid',
                'payment_method' => 'plexy',
                'paid_at' => now(),
            ]);
        }

        if ($lost) {
            $this->info('Помечено оплаченными: ' . count($lost));
        }

        return self::SUCCESS;
    }
}
