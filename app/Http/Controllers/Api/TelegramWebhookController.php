<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();
        
        Log::info('Telegram webhook', $update);

        // Проверяем что это сообщение с контактом
        if (isset($update['message']['contact'])) {
            $this->handleContact($update['message']);
        }

        return response()->json(['ok' => true]);
    }

    protected function handleContact(array $message)
    {
        $contact = $message['contact'];
        $phone = $contact['phone_number'];
        $telegramId = $contact['user_id'] ?? null;

        if (!$telegramId) {
            Log::warning('Contact without user_id', $contact);
            return;
        }

        // Находим пользователя и сохраняем телефон
        $user = User::where('telegram_id', (string) $telegramId)->first();

        if ($user) {
            // Чистим телефон
            $phone = preg_replace('/[^0-9]/', '', $phone);
            $user->update(['phone' => $phone]);
            
            Log::info('Phone saved', ['user_id' => $user->id, 'phone' => $phone]);
        }
    }
}