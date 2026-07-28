<?php

namespace App\Services;

use App\Models\Club;
use App\Models\CourtBooking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления клуба в Telegram о бронях/отменах из приложения.
 * Токен бота — clubs.telegram_bot_token, получатели — clubs.telegram_chat_ids.
 */
class ClubTelegramNotifier
{
    /** Отправить произвольный текст всем получателям клуба. */
    public static function send(Club $club, string $text): void
    {
        if (!$club->telegramNotifyReady()) {
            return;
        }
        $token = $club->telegram_bot_token;
        foreach ($club->telegramChatIds() as $chatId) {
            try {
                Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
            } catch (\Throwable $e) {
                Log::warning('ClubTelegramNotifier: не отправлено', [
                    'club' => $club->id, 'chat' => $chatId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Уведомление о брони. $kind: 'new' | 'cancel'.
     * В сообщении: имя, телефон, id игрока в приложении.
     */
    public static function notifyBooking(?CourtBooking $booking, string $kind): void
    {
        if (!$booking) {
            return;
        }
        $booking->loadMissing('court.club');
        $club = $booking->court?->club;
        if (!$club || !$club->telegramNotifyReady()) {
            return;
        }

        $head = $kind === 'cancel' ? '❌ Отмена брони' : '🎾 Новая бронь';
        $court = $booking->court?->name ?: 'Корт';
        $date = $booking->date ? \Illuminate\Support\Carbon::parse($booking->date)->format('d.m.Y') : '—';
        $time = substr((string) $booking->start_time, 0, 5) . '–' . substr((string) $booking->end_time, 0, 5);
        $name = trim((string) $booking->client_name) ?: '—';
        $phone = trim((string) $booking->client_phone) ?: '—';
        $uid = $booking->booked_by ? (string) $booking->booked_by : '—';
        $paid = $booking->club_card_id ? "\nОплата: клубная карта" : '';

        $text = e($head) . "\n"
            . e($club->name) . ' · ' . e($court) . "\n"
            . e($date . ' ' . $time) . "\n"
            . 'Имя: ' . e($name) . "\n"
            . 'Телефон: ' . e($phone) . "\n"
            . 'ID игрока: ' . e($uid)
            . $paid;

        self::send($club, $text);
    }
}
