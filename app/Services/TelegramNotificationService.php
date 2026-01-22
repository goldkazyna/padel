<?php

namespace App\Services;

use App\Models\User;
use App\Models\Tournament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    protected string $botToken;
    protected string $apiUrl;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    /**
     * Отправить сообщение пользователю
     */
    public function sendMessage(int $telegramId, string $message): bool
    {
        if (empty($this->botToken)) {
            Log::warning('Telegram bot token not configured');
            return false;
        }

        try {
            $response = Http::post("{$this->apiUrl}/sendMessage", [
                'chat_id' => $telegramId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            if (!$response->successful() || !$response->json('ok')) {
                Log::error('Telegram notification failed', [
                    'telegram_id' => $telegramId,
                    'response' => $response->json(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Telegram notification error', [
                'telegram_id' => $telegramId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Уведомление об одобрении заявки на турнир
     */
    public function notifyRegistrationApproved(User $user, Tournament $tournament): bool
    {
        if (!$user->telegram_id) {
            Log::info('User has no telegram_id', ['user_id' => $user->id]);
            return false;
        }

        $date = $tournament->start_date->format('d.m.Y');
        $time = $tournament->start_date->format('H:i');
        $clubName = $tournament->club->name ?? 'Клуб';

        $message = "🎉 <b>Ваша заявка одобрена!</b>\n\n"
            . "📌 <b>{$tournament->name}</b>\n"
            . "📅 {$date} в {$time}\n"
            . "📍 {$clubName}\n\n"
            . "Ждём вас на турнире! 🏆";

        return $this->sendMessage($user->telegram_id, $message);
    }

    /**
     * Уведомление об отклонении заявки
     */
    public function notifyRegistrationRejected(User $user, Tournament $tournament): bool
    {
        if (!$user->telegram_id) {
            return false;
        }

        $message = "😔 <b>Заявка отклонена</b>\n\n"
            . "К сожалению, ваша заявка на турнир «{$tournament->name}» была отклонена.\n\n"
            . "Следите за новыми турнирами!";

        return $this->sendMessage($user->telegram_id, $message);
    }

    /**
     * Напоминание о турнире (за день/час)
     */
    public function notifyTournamentReminder(User $user, Tournament $tournament, string $timeLeft): bool
    {
        if (!$user->telegram_id) {
            return false;
        }

        $date = $tournament->start_date->format('d.m.Y');
        $time = $tournament->start_date->format('H:i');
        $clubName = $tournament->club->name ?? 'Клуб';

        $message = "⏰ <b>Напоминание о турнире!</b>\n\n"
            . "📌 <b>{$tournament->name}</b>\n"
            . "📅 {$date} в {$time}\n"
            . "📍 {$clubName}\n\n"
            . "До начала: {$timeLeft}";

        return $this->sendMessage($user->telegram_id, $message);
    }
}