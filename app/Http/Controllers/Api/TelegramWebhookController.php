<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TelegramWebhookController extends Controller
{
	public function handle(Request $request)
	{
		$update = $request->all();
		
		Log::info('Telegram webhook', $update);

		// Контакт (номер телефона)
		if (isset($update['message']['contact'])) {
			$this->handleContact($update['message']);
			return response()->json(['ok' => true]);
		}

		// Текстовые сообщения (кнопки меню)
		if (isset($update['message']['text'])) {
			$text = $update['message']['text'];
			$chatId = $update['message']['chat']['id'];

			if (in_array($text, ['🏆 Турниры', '👤 Мой профиль', '🎾 Открыть приложение', '/start', '🏠 Главное меню', 'ℹ️ Помощь'])) {
				$this->sendWebAppButton($chatId);
			}
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
	
	protected function sendWebAppButton(int $chatId)
	{
		$botToken = config('services.telegram.bot_token');
		
		Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
			'chat_id' => $chatId,
			'text' => '🎾 Откройте приложение:',
			'reply_markup' => json_encode([
				'inline_keyboard' => [[
					['text' => '🎾 Открыть приложение', 'web_app' => ['url' => 'https://padel-p.kz/telegram/']]
				]]
			])
		]);
	}
}