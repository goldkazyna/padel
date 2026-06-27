<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\KingOfCourtMatch;
use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use App\Services\KingOfCourtService;
use Illuminate\Http\Request;

class KingOfCourtController extends Controller
{
    public function show(Tournament $tournament)
    {
        $tournament->load([
            'club',
            'participants',
            'kingOfCourtPlayers.user',
            'kingOfCourtRounds.matches.team1Player1',
            'kingOfCourtRounds.matches.team1Player2',
            'kingOfCourtRounds.matches.team2Player1',
            'kingOfCourtRounds.matches.team2Player2',
        ]);

        // Для фикс-пар — таблица по парам (иначе по игрокам в partial).
        $pairStandings = $tournament->isPairedKingOfCourt()
            ? app(KingOfCourtService::class)->getPairStandings($tournament)
            : null;

        return view('club.tournaments.kingofcourt.show', compact('tournament', 'pairStandings'));
    }

    /**
     * Страница «Создать пары» для фикс-парного Короля корта.
     */
    public function pairs(Tournament $tournament)
    {
        if (!$tournament->isPairedKingOfCourt()) {
            return redirect()->route('club.tournaments.show', $tournament);
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->orderBy('name')
            ->get();

        $existingPairs = $tournament->kingOfCourtPairs()->get();

        return view('club.tournaments.kingofcourt.pairs', compact('tournament', 'participants', 'existingPairs'));
    }

    /**
     * Сохранение пар. pairs[i][0] = player1_id, pairs[i][1] = player2_id.
     */
    public function storePairs(Request $request, Tournament $tournament, KingOfCourtService $service)
    {
        $validated = $request->validate([
            'pairs' => 'required|array|min:2',
            'pairs.*.0' => 'required|integer|exists:users,id',
            'pairs.*.1' => 'required|integer|exists:users,id',
        ]);

        [$ok, $message] = $service->createPairs($tournament, $validated['pairs']);
        if (!$ok) {
            return back()->with('error', $message)->withInput();
        }

        return redirect()->route('club.tournaments.show', $tournament)->with('success', $message);
    }

    public function saveScore(Request $request, KingOfCourtMatch $match, KingOfCourtService $service)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        if ($validated['team1_score'] === $validated['team2_score']) {
            return back()->with('error', 'В Король корта не может быть ничьей. Сыграйте до победы.');
        }

        $service->saveMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        return back()->with('success', 'Счёт сохранён!');
    }

    public function updateScore(Request $request, KingOfCourtMatch $match, KingOfCourtService $service)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        if ($validated['team1_score'] === $validated['team2_score']) {
            return back()->with('error', 'В Король корта не может быть ничьей.');
        }

        $service->saveMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        return back()->with('success', 'Счёт обновлён!');
    }

    public function generateNextRound(Tournament $tournament, KingOfCourtService $service)
    {
        if (!$service->canGenerateNextRound($tournament)) {
            return back()->with('error', 'Невозможно сгенерировать следующий раунд. Сначала доиграйте текущий.');
        }

        $ok = $service->generateNextRound($tournament);

        if (!$ok) {
            return back()->with('error', 'Ошибка генерации раунда');
        }

        $newRoundNumber = $tournament->kingOfCourtRounds()->max('round_number');
        $tournamentId = $tournament->id;
        $tournamentName = $tournament->name;

        // Отложенная отправка пушей — не блокируем редирект
        app()->terminating(function () use ($tournamentId, $tournamentName, $newRoundNumber) {
            self::notifyKocRoundGenerated($tournamentId, $tournamentName, (int) $newRoundNumber);
        });

        return back()->with('success', "Раунд {$newRoundNumber} сгенерирован! Игрокам отправлено уведомление.");
    }

    /**
     * Собрать и отправить пуш «сгенерирован раунд N» всем участникам KOC.
     * Публичный — вызывается и из веба, и из мобильной админки (единообразно).
     */
    public static function notifyKocRoundGenerated(int $tournamentId, string $tournamentName, int $roundNumber): void
    {
        self::notifyKocParticipants(
            $tournamentId,
            "Раунд {$roundNumber} сгенерирован",
            "{$tournamentName} — открой приложение, чтобы увидеть свой корт",
            [
                'type' => 'tournament',
                'category' => 'tournament',
                'subtype' => 'koc_round_generated',
                'tournament_id' => (string) $tournamentId,
                'round_number' => (string) $roundNumber,
            ]
        );
    }

    /**
     * Отправить пуш всем зарегистрированным участникам KOC-турнира +
     * записать в колокольчик. Используется через app()->terminating().
     */
    protected static function notifyKocParticipants(int $tournamentId, string $title, string $body, array $data = []): int
    {
        $userIds = \App\Models\KingOfCourtPlayer::where('tournament_id', $tournamentId)
            ->pluck('user_id')
            ->all();

        if (empty($userIds)) return 0;

        $users = User::whereIn('id', $userIds)
            ->whereHas('deviceTokens')
            ->with('deviceTokens')
            ->get();

        if ($users->isEmpty()) return 0;

        $tokens = $users->flatMap(fn($u) => $u->deviceTokens->pluck('token'))->all();

        $fcm = app(FCMNotificationService::class);
        $sent = $fcm->sendMulticastToTokens($tokens, $title, $body, $data);

        $now = now();
        $records = $users->map(fn($u) => [
            'user_id' => $u->id,
            'title' => $title,
            'body' => $body,
            'type' => $data['type'] ?? 'info',
            'category' => $data['category'] ?? 'tournament',
            'data' => json_encode($data),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        Notification::insert($records);

        return $sent;
    }
}
