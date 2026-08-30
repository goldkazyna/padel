<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\FormatsTournaments;
use App\Models\Tournament;
use App\Models\TournamentInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Приглашения игрока на турниры (сторона игрока).
 */
class MobileTournamentInvitationController extends Controller
{
    use FormatsTournaments;

    /**
     * GET /api/mobile/tournaments/invitations
     * Список ожидающих ответа приглашений текущего игрока.
     */
    public function index(Request $request): JsonResponse
    {
        $invitations = TournamentInvitation::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->with(['tournament.club', 'inviter:id,name'])
            ->whereHas('tournament', fn ($q) => $this->answerable($q))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($inv) => $this->format($inv))
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'invitations' => $invitations,
        ]);
    }

    /**
     * GET /api/mobile/tournaments/invitations/count
     * Число ожидающих приглашений (для бейджа).
     */
    public function count(Request $request): JsonResponse
    {
        $count = TournamentInvitation::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->whereHas('tournament', fn ($q) => $this->answerable($q))
            ->count();

        return response()->json(['success' => true, 'count' => $count]);
    }

    /**
     * POST /api/mobile/tournaments/invitations/{invitation}/accept
     */
    public function accept(Request $request, TournamentInvitation $invitation): JsonResponse
    {
        if ($invitation->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }
        if ($invitation->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Приглашение уже обработано'], 422);
        }

        $tournament = $invitation->tournament;
        if (!$tournament) {
            return response()->json(['success' => false, 'message' => 'Турнир недоступен'], 404);
        }

        // Приглашение могло висеть с прошлой недели — принимать нечего.
        if (in_array($tournament->status, ['completed', 'cancelled'], true)
            || ($tournament->start_date && $tournament->start_date->isPast())) {
            return response()->json([
                'success' => false,
                'message' => 'Турнир уже прошёл',
            ], 422);
        }

        $userId = $request->user()->id;

        $outcome = DB::transaction(function () use ($tournament, $userId) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            // Активной считается только запись в статусах registered/pending/waiting.
            // Отменённая (cancelled) запись не блокирует принятие приглашения.
            if ($tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending', 'waiting'])
                ->where('user_id', $userId)->exists()) {
                return 'already';
            }

            // Подчищаем ранее отменённую запись, чтобы attach не нарушил
            // уникальный индекс [tournament_id, user_id].
            $tournament->participants()->wherePivot('status', 'cancelled')->detach($userId);

            $takenSlots = $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending'])
                ->count();

            if (($takenSlots + 1) <= $tournament->max_participants) {
                $deadline = $tournament->moderationDeadline();
                $pivot = ['status' => 'pending'];
                if ($deadline) {
                    $pivot['moderation_deadline'] = $deadline;
                }
                $tournament->participants()->attach($userId, $pivot);
                return 'registered';
            }

            $waitlistTaken = $tournament->participants()
                ->wherePivot('status', 'waiting')
                ->count();
            $waitlistCapacity = (int) ($tournament->waitlist_size ?? 0);
            if ($waitlistCapacity > 0 && ($waitlistTaken + 1) <= $waitlistCapacity) {
                $tournament->participants()->attach($userId, ['status' => 'waiting']);
                return 'waitlisted';
            }

            return 'no_space';
        });

        if ($outcome === 'no_space') {
            return response()->json([
                'success' => false,
                'message' => 'Все места заняты, лист ожидания заполнен',
            ], 400);
        }

        $invitation->update(['status' => 'accepted', 'responded_at' => now()]);

        if ($outcome === 'registered') {
            $deadline = $tournament->moderationDeadline();
            if ($deadline) {
                app(\App\Services\ModerationNotifier::class)->pending($request->user(), $tournament, $deadline);
            }
        }

        return response()->json([
            'success' => true,
            'tournament_id' => $tournament->id,
            'waitlisted' => $outcome === 'waitlisted',
        ]);
    }

    /**
     * POST /api/mobile/tournaments/invitations/{invitation}/decline
     */
    public function decline(Request $request, TournamentInvitation $invitation): JsonResponse
    {
        if ($invitation->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Приглашение не найдено'], 404);
        }
        if ($invitation->status === 'pending') {
            $invitation->update(['status' => 'declined', 'responded_at' => now()]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/mobile/tournaments/invitable?user_id=X
     * Турниры, на которые можно позвать игрока X: открытые, индивидуальные,
     * по его уровню, где он ещё не участвует и не приглашён.
     */
    public function invitable(Request $request): JsonResponse
    {
        $validated = $request->validate(['user_id' => 'required|exists:users,id']);
        $player = User::findOrFail($validated['user_id']);
        $level = (float) $player->level;

        $tournaments = Tournament::where('status', 'open')
            ->where('type', '!=', 'team')
            ->where('min_level', '<=', $level)
            ->where('max_level', '>=', $level)
            ->whereHas('club', fn($q) => $q->where('is_test', false))
            ->whereNotExists(function ($q) use ($player) {
                $q->select(DB::raw(1))->from('tournament_participants')
                    ->whereColumn('tournament_participants.tournament_id', 'tournaments.id')
                    ->where('tournament_participants.user_id', $player->id)
                    ->whereIn('tournament_participants.status', ['registered', 'pending', 'waiting']);
            })
            ->whereNotExists(function ($q) use ($player) {
                $q->select(DB::raw(1))->from('tournament_invitations')
                    ->whereColumn('tournament_invitations.tournament_id', 'tournaments.id')
                    ->where('tournament_invitations.user_id', $player->id)
                    ->where('tournament_invitations.status', 'pending');
            })
            ->with('club')
            ->orderBy('start_date')
            ->get()
            ->map(fn($t) => $this->formatTournament($t, null, false))
            ->values();

        return response()->json(['success' => true, 'tournaments' => $tournaments]);
    }

    /**
     * POST /api/mobile/tournaments/{tournament}/invite-player
     * Позвать игрока на турнир (от текущего пользователя). Body: user_id.
     */
    public function invitePlayer(Request $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validate(['user_id' => 'required|exists:users,id']);
        $playerId = (int) $validated['user_id'];

        if ($playerId === $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Нельзя пригласить самого себя'], 422);
        }
        if ($tournament->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Турнир недоступен для приглашений'], 422);
        }
        if ($tournament->type === 'team') {
            return response()->json(['success' => false, 'message' => 'Приглашения доступны только для индивидуальных турниров'], 422);
        }

        $player = User::findOrFail($playerId);
        if ($player->level < $tournament->min_level || $player->level > $tournament->max_level) {
            return response()->json(['success' => false, 'message' => 'Турнир не подходит игроку по уровню'], 422);
        }
        if ($tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending', 'waiting'])
            ->where('users.id', $playerId)->exists()) {
            return response()->json(['success' => false, 'message' => 'Игрок уже участвует в турнире'], 422);
        }

        $invitation = TournamentInvitation::updateOrCreate(
            ['tournament_id' => $tournament->id, 'user_id' => $playerId],
            ['invited_by' => $request->user()->id, 'status' => 'pending', 'responded_at' => null],
        );

        $title = 'Приглашение на турнир';
        $inviter = $request->user()->name;
        $body = "{$inviter} зовёт вас на турнир «{$tournament->name}»";
        \App\Models\Notification::create([
            'user_id' => $player->id,
            'title' => $title,
            'body' => $body,
            'type' => 'tournament_invite',
            'category' => 'tournament',
            'data' => ['tournament_id' => $tournament->id, 'invitation_id' => $invitation->id],
        ]);
        try {
            app(\App\Services\FCMNotificationService::class)->sendToUser($player, $title, $body, [
                'type' => 'tournament_invite',
                'tournament_id' => (string) $tournament->id,
                'invitation_id' => (string) $invitation->id,
            ]);
        } catch (\Throwable $e) {
            // пуш не критичен — приглашение сохранено
        }

        return response()->json(['success' => true, 'message' => 'Приглашение отправлено']);
    }

    /**
     * Турниры, на которые приглашение ещё имеет смысл.
     *
     * Прошедший или отменённый турнир висел в списке с кнопкой «Принять»,
     * которая ничего не даёт: играть уже негде.
     */
    private function answerable($query)
    {
        return $query->whereNotIn('status', ['completed', 'cancelled'])
            ->where('start_date', '>', now());
    }

    private function format(TournamentInvitation $inv): ?array
    {
        $t = $inv->tournament;
        if (!$t) return null;

        return [
            'id' => $inv->id,
            'invited_by_name' => $inv->inviter?->name,
            'created_at' => $inv->created_at?->toIso8601String(),
            // Полный объект турнира — карточка выглядит как во вкладке турниров
            'tournament' => $this->formatTournament($t, null, false),
        ];
    }
}
