<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\RoundRobinMatch;
use App\Models\RoundRobinPlayer;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use App\Services\RoundRobinService;
use Illuminate\Http\Request;

class RoundRobinController extends Controller
{
    public function show(Tournament $tournament)
    {
        $tournament->load([
            'club',
            'participants',
            'roundRobinPlayers.user',
            'roundRobinRounds.matches.team1Player1',
            'roundRobinRounds.matches.team1Player2',
            'roundRobinRounds.matches.team2Player1',
            'roundRobinRounds.matches.team2Player2',
        ]);

        $standings = $tournament->roundRobinPlayers->count() > 0
            ? app(RoundRobinService::class)->standings($tournament)
            : [];

        return view('club.tournaments.round_robin.show', compact('tournament', 'standings'));
    }

    public function saveScore(Request $request, RoundRobinMatch $match, RoundRobinService $service)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        if ($validated['team1_score'] === $validated['team2_score']) {
            return back()->with('error', 'В Round Robin не может быть ничьей. Сыграйте до победы.');
        }

        $service->saveMatchResult($match, $validated['team1_score'], $validated['team2_score']);

        return back()->with('success', 'Счёт сохранён!');
    }

    public function updateScore(Request $request, RoundRobinMatch $match, RoundRobinService $service)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        if ($validated['team1_score'] === $validated['team2_score']) {
            return back()->with('error', 'В Round Robin не может быть ничьей.');
        }

        $service->saveMatchResult($match, $validated['team1_score'], $validated['team2_score']);

        return back()->with('success', 'Счёт обновлён!');
    }

    public function generateNextRound(Tournament $tournament, RoundRobinService $service)
    {
        if (!$service->canGenerateNextRound($tournament)) {
            return back()->with('error', 'Сначала доиграйте текущий раунд.');
        }

        if (!$service->generateNextRound($tournament)) {
            return back()->with('error', 'Ошибка генерации раунда');
        }

        $newRoundNumber = $tournament->roundRobinRounds()->max('round_number');
        $tournamentId = $tournament->id;
        $tournamentName = $tournament->name;

        app()->terminating(function () use ($tournamentId, $tournamentName, $newRoundNumber) {
            self::notifyRoundRobinRoundGenerated($tournamentId, $tournamentName, (int) $newRoundNumber);
        });

        return back()->with('success', "Раунд {$newRoundNumber} сгенерирован!");
    }

    /**
     * Собрать и отправить пуш «сгенерирован раунд N» всем участникам Round Robin.
     * Публичный — вызывается и из веба, и из мобильной админки (единообразно).
     */
    public static function notifyRoundRobinRoundGenerated(int $tournamentId, string $tournamentName, int $roundNumber): void
    {
        self::notifyParticipants(
            $tournamentId,
            "Раунд {$roundNumber} сгенерирован",
            "{$tournamentName} — открой приложение, чтобы увидеть свой корт",
            [
                'type' => 'tournament',
                'category' => 'tournament',
                'subtype' => 'round_robin_round_generated',
                'tournament_id' => (string) $tournamentId,
                'round_number' => (string) $roundNumber,
            ]
        );
    }

    protected static function notifyParticipants(int $tournamentId, string $title, string $body, array $data = []): int
    {
        $userIds = RoundRobinPlayer::where('tournament_id', $tournamentId)->pluck('user_id')->all();
        if (empty($userIds)) return 0;

        $users = User::whereIn('id', $userIds)->whereHas('deviceTokens')->with('deviceTokens')->get();
        if ($users->isEmpty()) return 0;

        $tokens = $users->flatMap(fn($u) => $u->deviceTokens->pluck('token'))->all();
        $sent = app(FCMNotificationService::class)->sendMulticastToTokens($tokens, $title, $body, $data);

        $now = now();
        Notification::insert($users->map(fn($u) => [
            'user_id' => $u->id,
            'title' => $title,
            'body' => $body,
            'type' => $data['type'] ?? 'info',
            'category' => $data['category'] ?? 'tournament',
            'data' => json_encode($data),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        return $sent;
    }
}
