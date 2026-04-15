<?php

namespace App\Services;

use App\Models\Club;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramChannelService
{
    protected string $botToken;
    protected string $apiUrl;
    protected string $channelId;
    protected string $botUsername;

    public function __construct(?Club $club = null)
    {
        $this->botToken = $club->telegram_bot_token ?? '';
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
        $this->channelId = $club->telegram_channel_id ?? '';
        $this->botUsername = config('services.telegram.bot_username', 'add_app_bot');
    }

    /**
     * Проверить, настроен ли Telegram для клуба
     */
    public function isConfigured(): bool
    {
        return !empty($this->botToken) && !empty($this->channelId);
    }

	/**
	 * Опубликовать анонс турнира в канал
	 */
	public function postTournament($tournament): bool
	{
		// Используем start_date (это datetime с датой и временем)
		$date = $tournament->start_date->format('d.m.Y');
		$time = $tournament->start_date->format('H:i');

		$price = $tournament->price > 0 ? number_format($tournament->price, 0, '', ' ') . ' ₸' : 'Бесплатно';

		$clubName = $tournament->club->name ?? 'Клуб';

		// Описание (если есть)
		$description = $tournament->description
			? "\n📝 {$tournament->description}\n"
			: '';

		$message = "🎾 <b>Новый турнир!</b>\n\n"
			. "📌 <b>{$tournament->name}</b>\n"
			. $description
			. "📅 {$date} в {$time}\n"
			. "📍 {$clubName}\n"
			. "💰 {$price}\n"
			. "👥 Мест: {$tournament->max_participants}\n"
			. "⭐ Уровень: {$tournament->min_level} - {$tournament->max_level}\n\n"
			. "Успей записаться!";

		return $this->sendWithButton($message, '📝 Записаться');
	}

    /**
     * Отправить сообщение с кнопкой Mini App
     */
    public function sendWithButton(string $message, string $buttonText): bool
    {
        $appUrl = "https://t.me/{$this->botUsername}/app";

        try {
            $response = Http::post("{$this->apiUrl}/sendMessage", [
                'chat_id' => $this->channelId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => $buttonText, 'url' => $appUrl]
                    ]]
                ]),
            ]);

            if (!$response->successful() || !$response->json('ok')) {
                Log::error('Telegram channel post failed', [
                    'response' => $response->json(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Telegram channel error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Простое сообщение в канал (без кнопки)
     */
    public function post(string $message): bool
    {
        try {
            $response = Http::post("{$this->apiUrl}/sendMessage", [
                'chat_id' => $this->channelId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            return $response->successful() && $response->json('ok');
        } catch (\Exception $e) {
            Log::error('Telegram channel error', ['error' => $e->getMessage()]);
            return false;
        }
    }
	/**
	 * Уведомление об освободившемся месте
	 */
	public function postSlotAvailable($tournament): bool
	{
		$date = $tournament->start_date->format('d.m.Y');
		$time = $tournament->start_date->format('H:i');
		$clubName = $tournament->club->name ?? 'Клуб';

		$message = "🎉 <b>Освободилось место!</b>\n\n"
			. "📌 <b>{$tournament->name}</b>\n"
			. "📅 {$date} в {$time}\n"
			. "📍 {$clubName}\n\n"
			. "Есть свободное место для участия! Успейте зарегистрироваться!";

		return $this->sendWithButton($message, '📝 Записаться');
	}
}
