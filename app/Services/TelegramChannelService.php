<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramChannelService
{
    protected string $botToken;
    protected string $apiUrl;
    protected string $channelId;
    protected string $botUsername;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
        $this->channelId = config('services.telegram.channel_id');
        $this->botUsername = config('services.telegram.bot_username', 'add_app_bot');
    }

    /**
     * Опубликовать анонс турнира в канал
     */
    public function postTournament($tournament): bool
    {
        $date = date('d.m.Y', strtotime($tournament->date));
        $time = date('H:i', strtotime($tournament->start_time));
        $price = $tournament->price > 0 ? number_format($tournament->price, 0, '', ' ') . ' ₸' : 'Бесплатно';
        
        $clubName = $tournament->club->name ?? 'Клуб';
        
        $message = "🎾 <b>Новый турнир!</b>\n\n"
            . "📌 <b>{$tournament->name}</b>\n"
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
}