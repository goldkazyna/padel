<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramAuthToken;
use App\Models\User;
use App\Support\TelegramPhoneLinker;
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
        // Ник телеграма бот присылает сам — раньше мы его просто выбрасывали,
        // и в базе на 1457 привязанных аккаунтов не было ни одного ника.
        $username = $from['username'] ?? null;

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
                'telegram_username' => $username,
                'level' => '1.0',
                'rating' => 1125,
                'email' => "tg_{$telegramId}@padel.local",
                'password' => Hash::make("tg_{$telegramId}_" . time()),
                'role' => 'player',
            ]);
        }

        // Ник дописываем и старым аккаунтам, но не затираем: человек мог
        // вписать свой в профиле руками.
        if (empty($user->telegram_username) && !empty($username)) {
            $user->update(['telegram_username' => $username]);
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

        // Защита от дублей: если номер уже привязан к ДРУГОМУ аккаунту, хелпер
        // сольёт telegram-идентичность в существующий и вернёт его.
        $duplicateId = $user->id;
        [$user, $merged] = TelegramPhoneLinker::linkPhone($user, $contact['phone_number']);

        // Специфика бота: переносим токены авторизации с дубля на существующий
        // аккаунт, чтобы приложение залогинило именно в реальный аккаунт.
        if ($merged) {
            TelegramAuthToken::where('user_id', $duplicateId)
                ->update(['user_id' => $user->id]);
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
