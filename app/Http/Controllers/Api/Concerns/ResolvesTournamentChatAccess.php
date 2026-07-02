<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Tournament;
use App\Models\TournamentChatMessage;
use App\Models\TournamentChatRead;
use App\Models\User;

/**
 * Права доступа к чату турнира (сервер — источник правды).
 *
 * Режимы записи (chat_write_mode):
 *  - admin        — пишет только организатор, читают организатор + участники;
 *  - participants — пишут и читают организатор + участники;
 *  - everyone     — пишут и читают все, кто видит турнир.
 *
 * После завершения/отмены турнира писать нельзя (только чтение).
 * Если chat_enabled = false — чат недоступен целиком.
 */
trait ResolvesTournamentChatAccess
{
    /** «Организатор»: суперадмин, создатель личного турнира, админ/модератор клуба. */
    protected function chatIsAdmin(?User $user, Tournament $t): bool
    {
        if (!$user) return false;
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) return true;
        if ($t->creator_id && (int) $t->creator_id === (int) $user->id) return true;

        $clubId = $t->club_id;
        if (!$clubId) return false;
        if ($user->adminClubs()->where('clubs.id', $clubId)->exists()) return true;
        return $user->moderatorClubs()->where('clubs.id', $clubId)->exists();
    }

    /** Участник турнира (одиночная регистрация или командный). */
    protected function chatIsParticipant(?User $user, Tournament $t): bool
    {
        if (!$user) return false;

        if ($t->usesSoloRegistration()) {
            return $t->participants()
                ->where('users.id', $user->id)
                ->wherePivotIn('status', ['registered', 'pending', 'approved'])
                ->exists();
        }

        return $t->teams()
            ->whereIn('status', ['approved', 'pending'])
            ->where(function ($q) use ($user) {
                $q->where('player1_id', $user->id)
                  ->orWhere('player2_id', $user->id);
            })
            ->exists();
    }

    protected function chatCanWrite(Tournament $t, bool $isAdmin, bool $isParticipant): bool
    {
        if (!$t->chat_enabled) return false;
        if (in_array($t->status, ['completed', 'cancelled'], true)) return false;

        return match ($t->chat_write_mode) {
            'admin' => $isAdmin,
            'everyone' => true,
            default => $isAdmin || $isParticipant, // participants
        };
    }

    protected function chatCanRead(Tournament $t, bool $isAdmin, bool $isParticipant): bool
    {
        if (!$t->chat_enabled) return false;

        return match ($t->chat_write_mode) {
            'everyone' => true,
            // admin и participants: читают организатор + участники
            default => $isAdmin || $isParticipant,
        };
    }

    /** Непрочитанные для текущего пользователя (чужие сообщения новее последнего прочитанного). */
    protected function chatUnreadCount(Tournament $t, User $user): int
    {
        $lastRead = (int) (TournamentChatRead::where('tournament_id', $t->id)
            ->where('user_id', $user->id)
            ->value('last_read_message_id') ?? 0);

        return TournamentChatMessage::where('tournament_id', $t->id)
            ->where('id', '>', $lastRead)
            ->where('user_id', '!=', $user->id)
            ->count();
    }

    /** Сериализованный блок chat для ответа деталей турнира. */
    protected function tournamentChatBlock(Tournament $t, ?User $user): array
    {
        $isAdmin = $this->chatIsAdmin($user, $t);
        $isParticipant = $this->chatIsParticipant($user, $t);
        $canRead = $this->chatCanRead($t, $isAdmin, $isParticipant);

        return [
            'enabled' => (bool) $t->chat_enabled,
            'write_mode' => $t->chat_write_mode ?? 'participants',
            'can_read' => $canRead,
            'can_write' => $this->chatCanWrite($t, $isAdmin, $isParticipant),
            'is_admin' => $isAdmin,
            'unread_count' => ($user && $canRead) ? $this->chatUnreadCount($t, $user) : 0,
        ];
    }

    /**
     * Множество user_id организаторов турнира — для бейджа «Организатор» у
     * автора сообщения (создатель + админы/модераторы клуба).
     */
    protected function chatAdminUserIds(Tournament $t): array
    {
        $ids = [];
        if ($t->creator_id) {
            $ids[] = (int) $t->creator_id;
        }
        if ($t->club_id) {
            $clubId = $t->club_id;
            $admins = User::whereHas('adminClubs', fn ($q) => $q->where('clubs.id', $clubId))->pluck('id');
            $mods = User::whereHas('moderatorClubs', fn ($q) => $q->where('clubs.id', $clubId))->pluck('id');
            $ids = array_merge($ids, $admins->all(), $mods->all());
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
