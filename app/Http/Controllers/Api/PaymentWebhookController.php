<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourtBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Приём вебхуков платёжного шлюза Plexy.
 *
 * Событие приходит POST'ом с телом:
 *   { "name": "transaction.charged", "merchantId": "...",
 *     "data": { "id": "txn_...", "amount": N, "status": "charged",
 *               "merchantReference": "booking-123", ... } }
 *
 * merchantReference = наш orderReference при создании ссылки ("booking-{id}").
 * Авторизация вебхука: заголовок Authorization (Bearer <секрет>), сверяем с
 * секретом клуба (clubs.plexy_webhook_secret, иначе env-дефолт).
 */
class PaymentWebhookController extends Controller
{
    public function plexy(Request $request)
    {
        $payload = $request->json()->all();

        // Лог любого входящего вебхука (диагностика). auth — обрезанный.
        Log::info('Plexy webhook IN', [
            'payload' => $payload,
            'auth' => substr((string) $request->header('Authorization'), 0, 24),
            'ip' => $request->ip(),
        ]);

        $name = (string) ($payload['name'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $reference = (string) ($data['merchantReference'] ?? $data['orderReference'] ?? '');

        $status = strtolower((string) ($data['status'] ?? ''));
        $isPaid = in_array($name, ['transaction.authorized', 'transaction.charged'], true)
            || in_array($status, ['authorized', 'charged', 'paid', 'success'], true);
        $isFailed = in_array($name, ['transaction.rejected', 'transaction.failed', 'transaction.cancelled'], true)
            || in_array($status, ['rejected', 'failed', 'cancelled'], true);

        // Счёт клиенту («paylink-{id}») — отдельная ветка, у него своя таблица.
        if (preg_match('/paylink-(\d+)/', $reference, $m)) {
            return $this->handlePaymentLink((int) $m[1], $request, $name, $isPaid, $isFailed);
        }

        // Находим бронь по ссылке "booking-{id}".
        $bookingId = null;
        if (preg_match('/booking-(\d+)/', $reference, $m)) {
            $bookingId = (int) $m[1];
        }
        $booking = $bookingId ? CourtBooking::find($bookingId) : null;

        if (!$booking) {
            Log::warning('Plexy webhook: бронь не найдена', ['reference' => $reference, 'name' => $name]);
            // 200 — чтобы Plexy не ретраил бесконечно по «чужой»/устаревшей ссылке.
            return response()->json(['success' => true, 'ignored' => true]);
        }

        $club = $booking->court?->club;

        // Проверка секрета вебхука (если задан у клуба/в env).
        $secret = $club?->plexyWebhookSecret();
        if (!empty($secret)) {
            $provided = $request->bearerToken() ?: (string) $request->header('Authorization');
            $provided = trim(preg_replace('/^Bearer\s+/i', '', $provided));
            if (!hash_equals($secret, $provided)) {
                Log::warning('Plexy webhook: неверный секрет', ['booking' => $booking->id]);
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
        }

        if ($isPaid && !$booking->is_paid) {
            $booking->update([
                'is_paid' => true,
                'payment_status' => 'paid',
                'payment_method' => 'plexy',
                'paid_at' => now(),
            ]);
            Log::info('Plexy webhook: бронь оплачена', ['booking' => $booking->id, 'event' => $name]);
        } elseif ($isFailed && !$booking->is_paid) {
            $booking->update(['payment_status' => 'failed']);
            Log::info('Plexy webhook: оплата не прошла', ['booking' => $booking->id, 'event' => $name]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Оплата счёта клиенту. Секрет проверяется по клубу счёта — у разных
     * клубов свои ключи.
     */
    private function handlePaymentLink(
        int $id,
        Request $request,
        string $event,
        bool $isPaid,
        bool $isFailed
    ) {
        $link = \App\Models\PaymentLink::with('club')->find($id);

        if (!$link) {
            Log::warning('Plexy webhook: счёт не найден', ['paylink' => $id, 'name' => $event]);
            // 200 — чтобы Plexy не ретраил по устаревшей ссылке.
            return response()->json(['success' => true, 'ignored' => true]);
        }

        $secret = $link->club?->plexyWebhookSecret();
        if (!empty($secret)) {
            $provided = $request->bearerToken() ?: (string) $request->header('Authorization');
            $provided = trim(preg_replace('/^Bearer\s+/i', '', $provided));
            if (!hash_equals($secret, $provided)) {
                Log::warning('Plexy webhook: неверный секрет (счёт)', ['paylink' => $link->id]);
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
        }

        if ($isPaid) {
            app(\App\Services\PaymentLinkService::class)->markPaid($link);
            Log::info('Plexy webhook: счёт оплачен', ['paylink' => $link->id, 'event' => $event]);
        } elseif ($isFailed && $link->status === \App\Models\PaymentLink::STATUS_PENDING) {
            Log::info('Plexy webhook: оплата счёта не прошла', ['paylink' => $link->id, 'event' => $event]);
        }

        return response()->json(['success' => true]);
    }
}
