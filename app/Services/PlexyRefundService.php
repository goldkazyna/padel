<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\PaymentLink;
use App\Models\TournamentPayment;
use App\Support\PlexyTransactions;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Возврат денег по транзакции Plexy — общий для веб-кабинета и приложения.
 *
 * Деньги наружу, поэтому статус и сумму спрашиваем у самого шлюза, а не верим
 * форме: вернуть можно только прошедший платёж и не больше оплаченного.
 *
 * Карточная оплата живёт в двух состояниях: деньги придержаны (authorized)
 * или уже списаны (charged). Списанное возвращают, придержанное отпускают —
 * для клуба это одно действие, разницу отрабатываем здесь.
 */
class PlexyRefundService
{
    /**
     * @return array{message: string, amount: float, charged: bool}
     * @throws RuntimeException с текстом для человека
     */
    public function refund(Club $club, string $transaction, float $amount, ?string $reason = null): array
    {
        if (!$club->hasPlexyConfigured()) {
            throw new RuntimeException('У клуба не настроена онлайн-оплата');
        }

        $plexy = new PlexyService($club->plexyApiKey());

        try {
            $tx = $plexy->getTransaction($transaction);
        } catch (\Throwable $e) {
            throw new RuntimeException('Транзакция не найдена у шлюза');
        }

        // Шлюз зовёт холд то AUTHORIZED, то AUTHED — в разных ручках по-разному.
        $status = strtoupper((string) ($tx['status'] ?? ''));
        $charged = str_contains($status, 'CHARGED');
        $authorized = str_contains($status, 'AUTH');

        if (!$charged && !$authorized) {
            throw new RuntimeException('Вернуть можно только прошедший платёж');
        }

        $paid = (float) ($tx['amount'] ?? 0);
        $amount = round($amount, 2);
        if ($amount > $paid) {
            throw new RuntimeException("Больше оплаченного вернуть нельзя: платёж на {$paid} ₸");
        }

        $paymentId = (string) ($tx['paymentId'] ?? $transaction);
        // Ссылка заказа зовётся по-разному: в списке транзакций
        // orderReference, в одиночной — merchantReference.
        $reference = (string) ($tx['orderReference'] ?? $tx['merchantReference'] ?? '');

        try {
            if ($charged) {
                $plexy->refund($paymentId, $amount);
            } else {
                // Частичное снятие холда шлюз может не принять — тогда
                // отпускаем всю сумму: клиенту так даже лучше.
                try {
                    $plexy->cancelAuthorization($paymentId, $amount < $paid ? $amount : null);
                } catch (\Throwable $e) {
                    $plexy->cancelAuthorization($paymentId);
                    $amount = $paid;
                }
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Шлюз отказал: ' . $e->getMessage());
        }

        // Деньги уже ушли клиенту: дальше ничему нельзя ронять ответ, иначе
        // получается ошибка поверх удавшегося возврата.
        try {
            $this->markRefunded($reference, $amount, $paid);

            ActivityLog::log(
                'refunded',
                'PlexyTransaction',
                null,
                "Возврат {$amount} ₸ по платежу " . ($reference !== '' ? $reference : $transaction)
                    . ($reason ? '. Причина: ' . $reason : ''),
                ['transaction' => $transaction, 'amount' => $amount, 'of' => $paid],
                $club->id,
            );

            // Список берётся из шлюза с минутным кэшем — иначе возврат «не виден».
            PlexyTransactions::forget($club);
        } catch (\Throwable $e) {
            Log::error('Возврат прошёл, но отметки не легли', [
                'transaction' => $transaction, 'error' => $e->getMessage(),
            ]);
        }

        return [
            'charged' => $charged,
            'amount' => $amount,
            'message' => $charged
                ? "Возврат {$amount} ₸ отправлен в банк"
                : "Холд на {$amount} ₸ снят — деньги вернутся клиенту",
        ];
    }

    /**
     * Снять отметку оплаты с того, за что платили.
     *
     * Возврат целиком — бронь снова не оплачена, иначе в расписании она так и
     * висит «оплачено», и деньги не сходятся. Частичный возврат отметку не
     * трогает: часть денег клуб получил.
     */
    private function markRefunded(string $reference, float $amount, float $paid): void
    {
        if ($amount + 0.01 < $paid) {
            return;   // вернули часть — бронь остаётся оплаченной
        }

        if (preg_match('/^booking-(\d+)$/', $reference, $m)) {
            CourtBooking::where('id', (int) $m[1])->update([
                'is_paid' => false,
                'payment_status' => 'refunded',
                'paid_at' => null,
            ]);
            return;
        }

        if (preg_match('/^paylink-(\d+)$/', $reference, $m)) {
            PaymentLink::where('id', (int) $m[1])->update(['status' => 'refunded']);
            return;
        }

        if (preg_match('/^tourpay-(\d+)$/', $reference, $m)) {
            // Участника из турнира не выкидываем: вернуть деньги и снять
            // человека с турнира — разные решения, второе принимает клуб.
            TournamentPayment::where('id', (int) $m[1])->update(['status' => 'refunded']);
        }
    }
}
