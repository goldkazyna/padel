<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Tournament;
use App\Models\TournamentInvitation;
use App\Models\User;

/**
 * Приглашение игрока на турнир: запись, уведомление в приложении и push.
 *
 * Живёт отдельно, потому что зовётся из двух мест — веб-админки и мобильного
 * API. Раньше текст приглашения был захардкожен в обоих контроллерах, и правка
 * в одном месте молча расходилась с другим.
 */
class TournamentInvitationService
{
    /** Заголовок по умолчанию — он же заготовка в форме. */
    public function defaultTitle(): string
    {
        return 'Приглашение на турнир';
    }

    /** Текст по умолчанию — он же заготовка в форме. */
    public function defaultBody(Tournament $tournament): string
    {
        return "Вас пригласили на турнир «{$tournament->name}»";
    }

    /**
     * Пригласить игрока.
     *
     * $title и $body — текст, поправленный организатором. Пустые значения
     * (в том числе одни пробелы) заменяются заготовкой, чтобы игроку не ушло
     * приглашение без текста.
     *
     * Повторное приглашение обновляет существующее и снова шлёт уведомление:
     * организатор нажимает «Пригласить» второй раз именно чтобы напомнить.
     */
    public function invite(
        Tournament $tournament,
        int $userId,
        int $invitedBy,
        ?string $title = null,
        ?string $body = null
    ): ?TournamentInvitation {
        $player = User::find($userId);
        if (!$player) {
            return null;
        }

        $invitation = TournamentInvitation::updateOrCreate(
            ['tournament_id' => $tournament->id, 'user_id' => $userId],
            ['invited_by' => $invitedBy, 'status' => 'pending', 'responded_at' => null],
        );

        $title = trim((string) $title) !== '' ? trim($title) : $this->defaultTitle();
        $body = trim((string) $body) !== '' ? trim($body) : $this->defaultBody($tournament);

        Notification::create([
            'user_id' => $player->id,
            'title' => $title,
            'body' => $body,
            'type' => 'tournament_invite',
            'category' => 'tournament',
            'data' => [
                'tournament_id' => $tournament->id,
                'invitation_id' => $invitation->id,
            ],
        ]);

        try {
            app(FCMNotificationService::class)->sendToUser($player, $title, $body, [
                'type' => 'tournament_invite',
                'tournament_id' => (string) $tournament->id,
                'invitation_id' => (string) $invitation->id,
            ]);
        } catch (\Throwable $e) {
            // Push не критичен — приглашение уже сохранено и видно в приложении.
        }

        return $invitation;
    }
}
