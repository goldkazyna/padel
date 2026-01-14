<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramChannelService
{
    protected string $botToken;
    protected string $apiUrl;
    protected string $channelId;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
        $this->channelId = config('services.telegram.channel_id');
    }

    /**
     * Опубликовать анонс турнира в канал
     */
    public function postTournament($tournament): bool
    {
        $date = date('d.m.Y', strtotime($tournament->date));
        $time = date('H:i', strtotime($tournament->start_time));
        $price = $tournament->price > 0 ? number_format($tournament->price, 0, '', ' ') . ' ₸' : 'Бесплатно';
        
        $message = "🎾 <b>Новый турнир!</b>\n\n"
            . "📌 <b>{$tournament->name}</b>\n"
            . "📅 {$date} в {$time}\n"
            . "💰 {$price}\n"
            . "👥 Мест: {$tournament->max_participants}\n"
            . "⭐ Уровень: {$tournament->min_level} - {$tournament->max_level}\n\n"
            . "Успей записаться!";

        return $this->sendWithButton($message, '📝 Записаться', $tournament->id);
    }

    /**
     * Отправить сообщение с кнопкой Mini App
     */
    protected function sendWithButton(string $message, string $buttonText, int $tournamentId = null): bool
    {
        $appUrl = config('app.url') . '/telegram/';
        
        // Если есть ID турнира — добавим в URL для открытия конкретного турнира
        if ($tournamentId) {
            $appUrl .= "?tournament={$tournamentId}";
        }

        try {
            $response = Http::post("{$this->apiUrl}/sendMessage", [
                'chat_id' => $this->channelId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        [
                            'text' => $buttonText,
                            'web_app' => ['url' => $appUrl]
                        ]
                    ]]
                ]),
            ]);

            if (!$response->successful()) {
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
     * Простое сообщение в канал
     */
    public function post(string $message): bool
    {
        try {
            $response = Http::post("{$this->apiUrl}/sendMessage", [
                'chat_id' => $this->channelId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram channel error', ['error' => $e->getMessage()]);
            return false;
        }
    }
}