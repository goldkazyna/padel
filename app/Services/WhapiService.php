<?php

namespace App\Services;

use App\Models\Club;
use App\Models\WhatsappMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp через Whapi.Cloud.
 *
 * Пока интеграция «мягкая»: принимаем входящие вебхуком и складываем в
 * `whatsapp_messages`, ничего не рассылая. Отправка есть отдельным методом
 * и вызывается только вручную — автоматических рассылок нет намеренно,
 * номер клуба живой, и блокировка WhatsApp за спам обошлась бы дорого.
 */
class WhapiService
{
    /**
     * Разобрать пакет вебхука и сохранить сообщения.
     *
     * Whapi шлёт массив `messages`; при ретраях тот же пакет приходит
     * повторно, поэтому запись заводится по уникальному id сообщения.
     *
     * @return int сколько сообщений сохранено (без учёта повторов)
     */
    public function storeWebhook(array $payload): int
    {
        $channelId = $payload['channel_id'] ?? null;
        $clubId = $this->clubIdFor($channelId);
        $saved = 0;

        foreach ($payload['messages'] ?? [] as $message) {
            if (!is_array($message) || empty($message['id'])) {
                continue;
            }

            $chatId = (string) ($message['chat_id'] ?? '');
            $phone = $this->phoneFrom($message);

            $row = WhatsappMessage::updateOrCreate(
                ['wa_message_id' => (string) $message['id']],
                [
                    'club_id' => $clubId,
                    'channel_id' => $channelId,
                    'chat_id' => $chatId,
                    'phone' => $phone,
                    'author_name' => $message['from_name'] ?? null,
                    'from_me' => (bool) ($message['from_me'] ?? false),
                    'type' => (string) ($message['type'] ?? 'text'),
                    'body' => $this->bodyFrom($message),
                    'payload' => $message,
                    'sent_at' => isset($message['timestamp'])
                        ? Carbon::createFromTimestamp((int) $message['timestamp'])
                        : now(),
                ]
            );

            if ($row->wasRecentlyCreated) {
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Страница истории из Whapi: сообщения от свежих к старым.
     * Нужна для выгрузки переписки за то время, когда вебхука ещё не было.
     */
    public function fetchHistory(int $count = 500, int $offset = 0): array
    {
        $token = (string) config('services.whapi.token');
        if ($token === '') {
            Log::warning('Whapi: токен не задан, выгрузка пропущена');
            return [];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->get(rtrim((string) config('services.whapi.url'), '/') . '/messages/list', [
                'count' => $count,
                'offset' => $offset,
            ]);

        if ($response->failed()) {
            Log::error('Whapi: не удалось выгрузить историю', [
                'status' => $response->status(),
                'offset' => $offset,
            ]);

            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * Отправить текст в WhatsApp.
     * Возвращает ответ Whapi или null, если канал не настроен.
     */
    public function sendText(string $phone, string $text): ?array
    {
        $token = (string) config('services.whapi.token');
        if ($token === '') {
            Log::warning('Whapi: токен не задан, отправка пропущена');
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        $response = Http::withToken($token)
            ->acceptJson()
            ->post(rtrim((string) config('services.whapi.url'), '/') . '/messages/text', [
                'to' => $digits,
                'body' => $text,
            ]);

        if ($response->failed()) {
            Log::error('Whapi: не удалось отправить сообщение', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response->json();
    }

    /**
     * Клуб, которому принадлежит канал.
     *
     * Каналов пока один, поэтому достаточно настройки в .env. Появится
     * второй клуб со своим WhatsApp — здесь вырастет карта каналов.
     */
    private function clubIdFor(?string $channelId): ?int
    {
        $configured = config('services.whapi.club_id');
        if ($configured) {
            return (int) $configured;
        }

        return Club::query()->value('id');
    }

    /** Номер собеседника: у исходящих он в chat_id, у входящих — в from. */
    private function phoneFrom(array $message): string
    {
        $source = ($message['from_me'] ?? false)
            ? ($message['chat_id'] ?? '')
            : ($message['from'] ?? $message['chat_id'] ?? '');

        return substr(preg_replace('/\D/', '', (string) $source), 0, 32);
    }

    /**
     * Текст сообщения. У Whapi он лежит в поле по имени типа: у текста —
     * `text.body`, у картинки с подписью — `image.caption` и так далее.
     */
    private function bodyFrom(array $message): ?string
    {
        $type = (string) ($message['type'] ?? 'text');
        $node = $message[$type] ?? null;

        if (is_string($node)) {
            return $node;
        }
        if (is_array($node)) {
            foreach (['body', 'caption', 'text', 'name'] as $key) {
                if (!empty($node[$key]) && is_string($node[$key])) {
                    return $node[$key];
                }
            }
        }

        return null;
    }
}
