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

        if (!$user) {
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

        $this->sendMessage($chatId, '✅ Вы авторизованы! Вернитесь в приложение.');

        return response()->json(['ok' => true]);
    }

    private function sendMessage(string $chatId, string $text): void
    {
        $botToken = config('services.telegram_mobile.bot_token');

        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
