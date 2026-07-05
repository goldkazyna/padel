<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\JustPadelItMatch;
use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use App\Services\JustPadelItService;
use Illuminate\Http\Request;

class JustPadelItController extends Controller
{
    public function show(Tournament $tournament)
    {
        $tournament->load([
            'club',
            'participants',
            'justPadelItPlayers.user',
            'justPadelItRounds.matches.team1Player1',
            'justPadelItRounds.matches.team1Player2',
            'justPadelItRounds.matches.team2Player1',
            'justPadelItRounds.matches.team2Player2',
        ]);

        // Для фикс-пар — таблица по парам (иначе по игрокам в partial).
        $pairStandings = $tournament->isPairedJustPadelIt()
            ? app(JustPadelItService::class)->getPairStandings($tournament)
            : null;

        return view('club.tournaments.justpadelit.show', compact('tournament', 'pairStandings'));
    }

    /**
     * Страница «Создать пары» для фикс-парного Just Padel It.
     */
    public function pairs(Tournament $tournament)
    {
        if (!$tournament->isPairedJustPadelIt()) {
            return redirect()->route('club.tournaments.show', $tournament);
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->orderBy('name')
            ->get();

        $existingPairs = $tournament->justPadelItPairs()->get();

        return view('club.tournaments.justpadelit.pairs', compact('tournament', 'participants', 'existingPairs'));
    }

    /**
     * Сохранение пар. pairs[i][0] = player1_id, pairs[i][1] = player2_id.
     */
    public function storePairs(Request $request, Tournament $tournament, JustPadelItService $service)
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

    public function saveScore(Request $request, JustPadelItMatch $match, JustPadelItService $service)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        if ($validated['team1_score'] === $validated['team2_score']) {
            return back()->with('error', 'В Just Padel It не может быть ничьей. Сыграйте до победы.');
        }

        $service->saveMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        return back()->with('success', 'Счёт сохранён!');
    }

    public function updateScore(Request $request, JustPadelItMatch $match, JustPadelItService $service)
    {
        $validated = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        if ($validated['team1_score'] === $validated['team2_score']) {
            return back()->with('error', 'В Just Padel It не может быть ничьей.');
        }

        $service->saveMatchResult(
            $match,
            $validated['team1_score'],
            $validated['team2_score']
        );

        return back()->with('success', 'Счёт обновлён!');
    }

    public function generateNextRound(Tournament $tournament, JustPadelItService $service)
    {
        if (!$service->canGenerateNextRound($tournament)) {
            return back()->with('error', 'Невозможно сгенерировать следующий раунд. Сначала доиграйте текущий.');
        }

        $ok = $service->generateNextRound($tournament);

        if (!$ok) {
            return back()->with('error', 'Ошибка генерации раунда');
        }

        $newRoundNumber = $tournament->justPadelItRounds()->max('round_number');
        $tournamentId = $tournament->id;
        $tournamentName = $tournament->name;

        // Отложенная отправка пушей — не блокируем редирект
        app()->terminating(function () use ($tournamentId, $tournamentName, $newRoundNumber) {
            self::notifyJpiRoundGenerated($tournamentId, $tournamentName, (int) $newRoundNumber);
        });

        return back()->with('success', "Раунд {$newRoundNumber} сгенерирован! Игрокам отправлено уведомление.");
    }

    /**
     * Экран посева перед стартом: авто-раскладка по кортам по рейтингу, редактируемая.
     */
    public function seeding(Tournament $tournament)
    {
        abort_unless($tournament->isJustPadelIt(), 404);
        if ($tournament->status !== 'open') {
            return redirect()->route('club.tournaments.show', $tournament);
        }
        // Парный режим сначала требует созданных пар.
        if ($tournament->isPairedJustPadelIt() && !$tournament->justPadelItPairs()->exists()) {
            return redirect()->route('club.justpadelit.pairs', $tournament)
                ->with('error', 'Сначала создайте пары');
        }
        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->orderByDesc('rating')->get();
        $courtsCount = (int) ($participants->count() / 4);
        return view('club.tournaments.justpadelit.seeding', compact('tournament', 'participants', 'courtsCount'));
    }

    /**
     * Старт с учётом порядка посева (order[] — id участников по кортам).
     */
    public function start(Request $request, Tournament $tournament, JustPadelItService $service)
    {
        abort_unless($tournament->isJustPadelIt(), 404);
        $order = $request->input('order', []);
        $order = is_array($order) ? array_map('intval', $order) : [];
        if ($service->startTournament($tournament, $order ?: null)) {
            return redirect()->route('club.tournaments.show', $tournament)
                ->with('success', 'Турнир начат');
        }
        return redirect()->route('club.tournaments.show', $tournament)
            ->with('error', 'Не удалось начать турнир (проверьте число игроков и пары)');
    }

    /**
     * Собрать и отправить пуш «сгенерирован раунд N» всем участникам JPI.
     * Публичный — вызывается и из веба, и из мобильной админки (единообразно).
     */
    public static function notifyJpiRoundGenerated(int $tournamentId, string $tournamentName, int $roundNumber): void
    {
        self::notifyJpiParticipants(
            $tournamentId,
            "Раунд {$roundNumber} сгенерирован",
            "{$tournamentName} — открой приложение, чтобы увидеть свой корт",
            [
                'type' => 'tournament',
                'category' => 'tournament',
                'subtype' => 'jpi_round_generated',
                'tournament_id' => (string) $tournamentId,
                'round_number' => (string) $roundNumber,
            ]
        );
    }

    /**
     * Отправить пуш всем зарегистрированным участникам JPI-турнира +
     * записать в колокольчик. Используется через app()->terminating().
     */
    protected static function notifyJpiParticipants(int $tournamentId, string $title, string $body, array $data = []): int
    {
        $userIds = \App\Models\JustPadelItPlayer::where('tournament_id', $tournamentId)
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
