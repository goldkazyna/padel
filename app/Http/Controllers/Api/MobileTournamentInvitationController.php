<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\FormatsTournaments;
use App\Models\Tournament;
use App\Models\TournamentInvitation;
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
            ->whereHas('tournament')
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
            ->whereHas('tournament')
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
