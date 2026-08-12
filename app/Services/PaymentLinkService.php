<?php

namespace App\Services;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Счета клиентам: платёжные ссылки Plexy на произвольную сумму.
 *
 * Сумма хранится в тенге, а в Plexy уходит в тиынах (×100) — так же, как в
 * бронях кортов. Оплату приносит вебхук по «paylink-{id}»; на случай, если
 * он не дошёл, есть sync() — прямой опрос шлюза.
 */
class PaymentLinkService
{
    /** Статусы шлюза, означающие «деньги получены». */
    private const PAID_STATUSES = ['paid', 'charged', 'authorized', 'success', 'completed'];

    /**
     * Выставить счёт.
     *
     * @param  array{amount: numeric, description: string, expires_in_hours: int,
     *               club_client_id?: int|null, client_name?: string|null,
     *               client_phone?: string|null, note?: string|null} $data
     *
     * @throws RuntimeException если у клуба не настроен Plexy или шлюз отказал.
     */
    public function create(Club $club, User $author, array $data): PaymentLink
    {
        if (!$club->hasPlexyConfigured()) {
            throw new RuntimeException('У клуба не настроена онлайн-оплата');
        }

        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            throw new RuntimeException('Сумма должна быть больше нуля');
        }

        $client = !empty($data['club_client_id'])
            ? ClubClient::where('club_id', $club->id)->find($data['club_client_id'])
            : null;

        $expiresAt = now()->addHours(max(1, (int) ($data['expires_in_hours'] ?? 24)));

        $link = PaymentLink::create([
            'club_id' => $club->id,
            'created_by' => $author->id,
            'club_client_id' => $client?->id,
            'amount' => $amount,
            'description' => $data['description'],
            'client_name' => $client?->name ?: ($data['client_name'] ?? null),
            'client_phone' => $client?->phone ?: ($data['client_phone'] ?? null),
            'status' => PaymentLink::STATUS_PENDING,
            'expires_at' => $expiresAt,
            'note' => $data['note'] ?? null,
        ]);

        try {
            // orderReference знает id счёта, поэтому запись создаётся первой.
            $result = (new PlexyService($club->plexyApiKey()))->createPaymentLink(
                amount: (int) round($amount * 100),
                description: mb_substr($data['description'], 0, 200),
                orderReference: $link->orderReference(),
                expiresAt: $expiresAt,
                metadata: ['payment_link_id' => $link->id, 'club_id' => $club->id],
            );
        } catch (\Throwable $e) {
            // Ссылки нет — счёт без url только засорил бы список.
            $link->delete();
            Log::error('PaymentLink: не удалось создать ссылку', [
                'club' => $club->id, 'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Не удалось создать ссылку: ' . $e->getMessage());
        }

        $link->update([
            'plexy_link_id' => $result['id'] ?? null,
            'plexy_url' => $result['url'] ?? null,
        ]);

        return $link->fresh();
    }

    /**
     * Отменить счёт: гасим ссылку в Plexy и помечаем локально.
     *
     * @throws RuntimeException если счёт уже оплачен.
     */
    public function cancel(PaymentLink $link): void
    {
        if ($link->isPaid()) {
            throw new RuntimeException('Оплаченный счёт отменить нельзя');
        }

        $key = $link->club?->plexyApiKey();
        if ($key && $link->plexy_link_id) {
            try {
                Http::withHeaders(['Authorization' => $key])
                    ->acceptJson()
                    ->timeout(15)
                    ->post(rtrim((string) config('services.plexy.base_url', 'https://api.plexypay.com'), '/')
                        . '/v1/payment-links/' . $link->plexy_link_id . '/cancel');
            } catch (\Throwable $e) {
                // Локально всё равно закрываем: для клуба счёт недействителен.
                Log::warning('PaymentLink: отмена в Plexy не прошла', [
                    'link' => $link->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $link->update(['status' => PaymentLink::STATUS_CANCELLED]);
    }

    /**
     * Спросить шлюз о состоянии счёта — на случай, если вебхук не дошёл.
     * Возвращает true, если статус изменился.
     */
    public function sync(PaymentLink $link): bool
    {
        if ($link->isPaid() || !$link->plexy_link_id) {
            return false;
        }

        $key = $link->club?->plexyApiKey();
        if (!$key) {
            return false;
        }

        try {
            $info = (new PlexyService($key))->getPaymentLink($link->plexy_link_id);
        } catch (\Throwable $e) {
            Log::warning('PaymentLink: не удалось получить статус', [
                'link' => $link->id, 'error' => $e->getMessage(),
            ]);
            return false;
        }

        $status = strtolower((string) ($info['status'] ?? ''));

        if (in_array($status, self::PAID_STATUSES, true)) {
            $this->markPaid($link);
            return true;
        }

        if ($status === 'expired' && $link->status === PaymentLink::STATUS_PENDING) {
            $link->update(['status' => PaymentLink::STATUS_EXPIRED]);
            return true;
        }

        return false;
    }

    /** Пометить счёт оплаченным (вызывается и вебхуком, и sync). */
    public function markPaid(PaymentLink $link): void
    {
        if ($link->isPaid()) {
            return;
        }

        $link->update([
            'status' => PaymentLink::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }
}
