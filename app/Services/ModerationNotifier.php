<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;
use Carbon\Carbon;

class ModerationNotifier
{
    public function pending(User $user, Tournament $t, Carbon $deadline): void
    {
        $when = $deadline->copy()->timezone('Asia/Almaty')->format('d.m H:i');
        $this->send($user, $t, 'tournament_moderation_pending',
            'Заявка на модерации',
            "Оплатите участие в «{$t->name}» до {$when}, иначе заявку снимут");
    }

    public function reminder(User $user, Tournament $t, Carbon $deadline): void
    {
        $left = now()->diffForHumans($deadline, ['parts' => 2, 'short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE]);
        $this->send($user, $t, 'tournament_moderation_reminder',
            'Скоро снимем заявку',
            "Осталось {$left} — оплатите участие в «{$t->name}»");
    }

    public function expired(User $user, Tournament $t): void
    {
        $this->send($user, $t, 'tournament_moderation_expired',
            'Заявка снята',
            "Заявка на «{$t->name}» снята — оплата не поступила вовремя");
    }

    private function send(User $user, Tournament $t, string $type, string $title, string $body): void
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'category' => 'tournament',
            'data' => ['tournament_id' => $t->id],
        ]);
        try {
            app(\App\Services\FCMNotificationService::class)->sendToUser($user, $title, $body, [
                'type' => $type,
                'tournament_id' => (string) $t->id,
            ]);
        } catch (\Throwable $e) {
            // пуш не критичен (в т.ч. если Firebase не сконфигурирован)
        }
    }
}
