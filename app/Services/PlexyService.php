<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Клиент платёжного шлюза Plexy (developer.plexy.money / api.plexypay.com).
 *
 * Авторизация — заголовок `Authorization: <api_key>` (БЕЗ «Bearer», несмотря
 * на пример в доке). Ключи у каждого клуба свои (clubs.plexy_api_key), сюда
 * передаём эффективный ключ (Club::plexyApiKey()).
 *
 * Поток: createPaymentLink() → возвращает url (checkout.plexypay.com), которую
 * открывает приложение. По факту оплаты Plexy шлёт вебхук (transaction.*).
 */
class PlexyService
{
    private string $baseUrl;

    public function __construct(private string $apiKey, ?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?: (string) config('services.plexy.base_url', 'https://api.plexypay.com'), '/');
    }

    /**
     * Создать платёжную ссылку.
     *
     * $amount — в МИНОРНЫХ единицах (тиынах): 22 000 ₸ передаются как
     * 2 200 000. Проверено на боевых платежах.
     *
     * Возвращает ['id' => 'pl_...', 'url' => 'https://checkout...', 'status' => 'active'].
     *
     * @throws \RuntimeException при ошибке API.
     */
    public function createPaymentLink(
        int $amount,
        string $description,
        string $orderReference,
        \DateTimeInterface $expiresAt,
        string $currency = 'KZT',
        array $metadata = []
    ): array {
        $resp = Http::withHeaders(['Authorization' => $this->apiKey])
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl . '/v1/payment-links', [
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
                'orderReference' => $orderReference,
                // Plexy ждёт UTC ISO-8601 с миллисекундами и Z.
                'expiresAt' => \Carbon\Carbon::instance(
                    \Carbon\Carbon::parse($expiresAt)
                )->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'metadata' => (object) $metadata,
            ]);

        if (!$resp->successful()) {
            $msg = $resp->json('message') ?: $resp->body();
            throw new \RuntimeException('Plexy createPaymentLink: ' . $msg);
        }

        $d = $resp->json();
        return [
            'id' => $d['id'] ?? null,
            'url' => $d['url'] ?? null,
            'status' => $d['status'] ?? null,
        ];
    }

    /** Получить состояние платёжной ссылки (GET /v1/payment-links/{id}). */
    public function getPaymentLink(string $id): array
    {
        $resp = Http::withHeaders(['Authorization' => $this->apiKey])
            ->acceptJson()
            ->timeout(15)
            ->get($this->baseUrl . '/v1/payment-links/' . $id);

        if (!$resp->successful()) {
            $msg = $resp->json('message') ?: $resp->body();
            throw new \RuntimeException('Plexy getPaymentLink: ' . $msg);
        }

        return $resp->json();
    }

    /**
     * Все транзакции мерчанта — не только те, что мы выставляли сами.
     *
     * Сюда попадают и оплаты из приложения (бронь, турнир), и счета из CRM,
     * и ссылки, созданные прямо в кабинете Plexy: у клуба одна касса, и видеть
     * её он должен целиком.
     *
     * ВАЖНО про единицы: здесь суммы приходят в ТЕНГЕ, а в /v1/payment-links —
     * в тиынах (×100). Проверено на одном и том же платеже.
     *
     * @return array{data: array<int, array<string, mixed>>, page: int, size: int, total: int}
     */
    public function listTransactions(int $page = 1, int $size = 50): array
    {
        $resp = Http::withHeaders(['Authorization' => $this->apiKey])
            ->acceptJson()
            ->timeout(20)
            ->get($this->baseUrl . '/v1/transactions', ['page' => $page, 'size' => $size]);

        if (!$resp->successful()) {
            $msg = $resp->json('message') ?: $resp->body();
            throw new \RuntimeException('Plexy listTransactions: ' . $msg);
        }

        $data = $resp->json();

        return [
            'data' => $data['data'] ?? [],
            'page' => (int) ($data['page'] ?? $page),
            'size' => (int) ($data['size'] ?? $size),
            'total' => (int) ($data['total'] ?? count($data['data'] ?? [])),
        ];
    }
}
