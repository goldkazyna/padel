<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramAuthToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramMobileWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();

        Log::info('Telegram mobile webhook', $update);

        // Обработка контакта (номер телефона)
        if (isset($update['message']['contact'])) {
            $this->handleContact($update['message']);
            return response()->json(['ok' => true]);
        }

        if (!isset($update['message']['text'])) {
            return response()->json(['ok' => true]);
        }

        $text = $update['message']['text'];
        $chatId = $update['message']['chat']['id'];

        // Парсим /start auth_{token}
        if (!preg_match('/^\/start\s+auth_(.+)$/', $text, $matches)) {
            $this->sendMessage($chatId, 'Используйте кнопку авторизации в приложении.');
            return response()->json(['ok' => true]);
        }

        $token = $matches[1];

        // Ищем токен (не истёкший, без привязанного юзера)
        $authToken = TelegramAuthToken::notExpired()
            ->whereNull('user_id')
            ->where('token', $token)
            ->first();

        if (!$authToken) {
            $this->sendMessage($chatId, 'Ссылка устарела. Попробуйте снова в приложении.');
            return response()->json(['ok' => true]);
        }

        // Данные из Telegram
        $from = $update['message']['from'];
        $telegramId = (string) $from['id'];
        $firstName = $from['first_name'] ?? '';
        $lastName = $from['last_name'] ?? '';

        // Ищем или создаём юзера
        $user = User::where('telegram_id', $telegramId)->first();
        $isNewUser = false;

        if (!$user) {
            $isNewUser = true;
            $user = User::create([
                'name' => trim($firstName . ' ' . $lastName),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'telegram_id' => $telegramId,
                'level' => '1.0',
                'rating' => 1125,
                'email' => "tg_{$telegramId}@padel.local",
                'password' => Hash::make("tg_{$telegramId}_" . time()),
                'role' => 'player',
            ]);
        }

        // Привязываем юзера к токену
        $authToken->update(['user_id' => $user->id]);

        // Ответ в зависимости от статуса юзера
        if ($isNewUser || !$user->phone) {
            $message = $isNewUser
                ? '✅ Вы зарегистрированы! Для завершения поделитесь номером телефона 👇'
                : '✅ Вы авторизованы! Поделитесь номером телефона для завершения 👇';

            $this->sendMessageWithContactRequest($chatId, $message);
        } else {
            $this->sendMessage($chatId, '✅ Вы авторизованы! Вернитесь в приложение Padel-KZ.');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Свежесозданный (на /start) telegram-аккаунт «слить» в существующий
     * аккаунт с тем же телефоном, чтобы не плодить дубли.
     *
     * Ничего не удаляет: переносит telegram_id и токены авторизации на
     * существующий аккаунт, а у дубля отвязывает telegram_id (чтобы будущие
     * входы через бота резолвились на правильный аккаунт). Пустой дубль
     * подчищается отдельно при ручном дедупе. Возвращает канонический аккаунт.
     */
    private function mergeTelegramAccount(User $duplicate, User $existing): User
    {
        // Привязываем telegram_id к существующему, если у него его ещё нет.
        if (empty($existing->telegram_id)) {
            $existing->update(['telegram_id' => $duplicate->telegram_id]);
        }

        // Переносим токены авторизации с дубля на существующий аккаунт, чтобы
        // приложение залогинило именно в реальный аккаунт.
        TelegramAuthToken::where('user_id', $duplicate->id)
            ->update(['user_id' => $existing->id]);

        // Отвязываем telegram_id у дубля, если он совпал с перенесённым, иначе
        // /start снова попадал бы на дубль и коллизия повторялась.
        if ((string) $duplicate->telegram_id === (string) $existing->telegram_id) {
            $duplicate->update(['telegram_id' => null]);
        }

        Log::info('Telegram phone merge: дубль подавлен', [
            'duplicate_id' => $duplicate->id,
            'existing_id' => $existing->id,
            'phone' => $existing->phone,
        ]);

        return $existing;
    }

    private function handleContact(array $message): void
    {
        $contact = $message['contact'];
        $chatId = $message['chat']['id'];
        $telegramId = (string) ($contact['user_id'] ?? null);

        if (!$telegramId) {
            return;
        }

        $user = User::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return;
        }

        // Нормализуем телефон (только цифры).
        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number']);

        // Защита от дублей: если этот номер уже привязан к ДРУГОМУ аккаунту —
        // не плодим второй, а логиним пользователя в его настоящий аккаунт.
        $existing = User::where('phone', $phone)
            ->where('id', '!=', $user->id)
            ->orderBy('id')
            ->first();

        if ($existing) {
            $user = $this->mergeTelegramAccount($user, $existing);
        } else {
            $user->update(['phone' => $phone]);
        }

        $botToken = config('services.telegram_mobile.bot_token');

        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => '✅ Спасибо! Номер сохранён. Вернитесь в приложение Padel-KZ.',
            'reply_markup' => json_encode(['remove_keyboard' => true]),
        ]);
    }

    private function sendMessage(string $chatId, string $text): void
    {
        $botToken = config('services.telegram_mobile.bot_token');

        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    private function sendMessageWithContactRequest(string $chatId, string $text): void
    {
        $botToken = config('services.telegram_mobile.bot_token');

        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode([
                'keyboard' => [[
                    [
                        'text' => '📱 Поделиться номером телефона',
                        'request_contact' => true,
                    ],
                ]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]),
        ]);
    }
}
