<?php

namespace App\Support;

use App\Models\Club;
use App\Models\CourtBooking;
use App\Models\PaymentLink;
use App\Models\TournamentPayment;
use App\Services\PlexyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Касса клуба целиком: все транзакции Plexy, а не только счета из CRM.
 *
 * Клуб видит одну кассу: туда приходят оплаты броней и турниров из приложения,
 * счета, выставленные на ресепшене, и ссылки, созданные прямо в кабинете
 * Plexy. Раньше в CRM были видны только последние — и деньги «не сходились».
 *
 * Каждую транзакцию подписываем: по `orderReference` находим, за что платили.
 */
class PlexyTransactions
{
    /** Сколько секунд держим ответ шлюза: страницу открывают часто. */
    private const CACHE_SECONDS = 60;

    /**
     * Страница транзакций с расшифровкой, за что платили.
     *
     * @return array{rows: array<int, array<string, mixed>>, page: int, size: int, total: int}
     */
    public static function page(Club $club, int $page = 1, int $size = 50, bool $fresh = false): array
    {
        $key = "plexy_tx:{$club->id}:{$page}:{$size}";

        if ($fresh) {
            Cache::forget($key);
        }

        $raw = Cache::remember($key, self::CACHE_SECONDS, function () use ($club, $page, $size) {
            $service = new PlexyService($club->plexyApiKey());

            return $service->listTransactions($page, $size);
        });

        return [
            'rows' => self::describe($raw['data'] ?? []),
            'page' => $raw['page'] ?? $page,
            'size' => $raw['size'] ?? $size,
            'total' => $raw['total'] ?? 0,
        ];
    }

    /**
     * Расшифровать ссылки заказов пачкой.
     *
     * @param  array<int, array<string, mixed>> $transactions
     * @return array<int, array<string, mixed>>
     */
    private static function describe(array $transactions): array
    {
        $bookingIds = [];
        $linkIds = [];
        $tourIds = [];

        foreach ($transactions as $tx) {
            $ref = (string) ($tx['orderReference'] ?? '');
            if (preg_match('/^booking-(\d+)$/', $ref, $m)) {
                $bookingIds[] = (int) $m[1];
            } elseif (preg_match('/^paylink-(\d+)$/', $ref, $m)) {
                $linkIds[] = (int) $m[1];
            } elseif (preg_match('/^tourpay-(\d+)$/', $ref, $m)) {
                $tourIds[] = (int) $m[1];
            }
        }

        $bookings = CourtBooking::with(['court'])->whereIn('id', $bookingIds)->get()->keyBy('id');
        $links = PaymentLink::whereIn('id', $linkIds)->get()->keyBy('id');
        $payments = TournamentPayment::with(['tournament', 'user'])->whereIn('id', $tourIds)->get()->keyBy('id');

        $rows = [];
        foreach ($transactions as $tx) {
            $ref = (string) ($tx['orderReference'] ?? '');
            $rows[] = [
                'id' => $tx['transactionId'] ?? null,
                'rrn' => $tx['rrn'] ?? null,
                // Суммы транзакций приходят уже в тенге.
                'amount' => (float) ($tx['amount'] ?? 0),
                'status' => self::status((string) ($tx['status'] ?? '')),
                'status_raw' => $tx['status'] ?? null,
                'created_at' => isset($tx['createdAt']) ? Carbon::parse($tx['createdAt']) : null,
                'reference' => $ref,
            ] + self::subject($ref, $bookings, $links, $payments);
        }

        return $rows;
    }

    /**
     * За что платили: тип, подпись и куда провалиться.
     *
     * @return array{kind: string, title: string, subtitle: ?string, url: ?string}
     */
    private static function subject(string $ref, $bookings, $links, $payments): array
    {
        if (preg_match('/^booking-(\d+)$/', $ref, $m)) {
            $booking = $bookings[(int) $m[1]] ?? null;

            return [
                'kind' => 'booking',
                'title' => 'Бронь корта',
                'subtitle' => $booking
                    ? trim(($booking->court?->name ?? 'Корт') . ' · '
                        . Carbon::parse($booking->date)->format('d.m.Y') . ' '
                        . substr((string) $booking->start_time, 0, 5)
                        . ($booking->client_name ? ' · ' . $booking->client_name : ''))
                    : 'бронь удалена',
                'url' => $booking ? route('club.courts.schedule', ['date' => Carbon::parse($booking->date)->toDateString()]) : null,
            ];
        }

        if (preg_match('/^paylink-(\d+)$/', $ref, $m)) {
            $link = $links[(int) $m[1]] ?? null;

            return [
                'kind' => 'paylink',
                'title' => 'Счёт клиенту',
                'subtitle' => $link
                    ? trim((string) $link->description . ($link->client_name ? ' · ' . $link->client_name : ''))
                    : 'счёт удалён',
                'url' => route('club.payments.index'),
            ];
        }

        if (preg_match('/^tourpay-(\d+)$/', $ref, $m)) {
            $payment = $payments[(int) $m[1]] ?? null;

            return [
                'kind' => 'tournament',
                'title' => 'Участие в турнире',
                'subtitle' => $payment
                    ? trim(($payment->tournament?->name ?? 'Турнир')
                        . ($payment->user?->name ? ' · ' . $payment->user->name : ''))
                    : 'платёж удалён',
                'url' => $payment?->tournament
                    ? route('club.tournaments.show', $payment->tournament_id)
                    : null,
            ];
        }

        // Ссылка не наша: её создали прямо в кабинете Plexy. Это не ошибка —
        // клубу важно видеть и такие платежи, иначе касса не сходится.
        return [
            'kind' => 'external',
            'title' => 'Оплата вне приложения',
            'subtitle' => $ref !== '' ? $ref : null,
            'url' => null,
        ];
    }

    /** Понятный статус вместо TRANSACTION_STATUS_CHARGED. */
    private static function status(string $raw): string
    {
        $short = str_replace('TRANSACTION_STATUS_', '', strtoupper($raw));

        return match ($short) {
            'CHARGED', 'PAID', 'SUCCESS' => 'paid',
            'AUTHORIZED', 'PENDING', 'PROCESSING' => 'pending',
            'REFUNDED', 'REVERSED' => 'refunded',
            'REJECTED', 'FAILED', 'DECLINED', 'CANCELLED' => 'failed',
            default => strtolower($short) ?: 'unknown',
        };
    }
}
