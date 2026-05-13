<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\BaliKocMatch;
use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;
use App\Services\BaliKocService;
use Illuminate\Http\Request;

class BaliKocController extends Controller
{
    public function show(Tournament $tournament)
    {
        $tournament->load([
            'club',
            'participants',
            'baliKocPairs.player1',
            'baliKocPairs.player2',
            'baliKocRounds.matches.pair1.player1',
            'baliKocRounds.matches.pair1.player2',
            'baliKocRounds.matches.pair2.player1',
            'baliKocRounds.matches.pair2.player2',
        ]);

        $standings = app(BaliKocService::class)->getStandings($tournament);

        return view('club.tournaments.bali_koc.show', compact('tournament', 'standings'));
    }

    /**
     * Страница «Создать пары»: показывает зарегистрированных игроков и форму
     * с селектами для каждой пары.
     */
    public function pairs(Tournament $tournament)
    {
        if (!$tournament->isBaliKoc()) {
            return redirect()->route('club.tournaments.show', $tournament);
        }

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->orderBy('name')
            ->get();

        $existingPairs = $tournament->baliKocPairs()->get();

        return view('club.tournaments.bali_koc.pairs', compact('tournament', 'participants', 'existingPairs'));
    }

    /**
     * Сохранение пар, созданных админом.
     * Ожидаем pairs[i][0] = player1_id, pairs[i][1] = player2_id.
     */
    public function storePairs(Request $request, Tournament $tournament, BaliKocService $service)
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

    public function saveScore(Request $request, BaliKocMatch $match, BaliKocService $service)
    {
        $validated = $request->validate([
            'pair1_games' => 'required|integer|min:0',
            'pair2_games' => 'required|integer|min:0',
        ]);

        if ($validated['pair1_games'] === $validated['pair2_games']) {
            return back()->with('error', 'Ничья не допускается — играем до победы.');
        }

        $service->saveMatchResult($match, $validated['pair1_games'], $validated['pair2_games']);
        return back()->with('success', 'Счёт сохранён!');
    }

    public function updateScore(Request $request, BaliKocMatch $match, BaliKocService $service)
    {
        $validated = $request->validate([
            'pair1_games' => 'required|integer|min:0',
            'pair2_games' => 'required|integer|min:0',
        ]);

        if ($validated['pair1_games'] === $validated['pair2_games']) {
            return back()->with('error', 'Ничья не допускается.');
        }

        $service->saveMatchResult($match, $validated['pair1_games'], $validated['pair2_games']);
        return back()->with('success', 'Счёт обновлён!');
    }

    public function generateNextRound(Tournament $tournament, BaliKocService $service)
    {
        if (!$service->canGenerateNextRound($tournament)) {
            return back()->with('error', 'Невозможно сгенерировать следующий раунд. Сначала доиграйте текущий.');
        }

        $ok = $service->generateNextRound($tournament);
        if (!$ok) {
            return back()->with('error', 'Ошибка генерации раунда');
        }

        $newRoundNumber = (int) $tournament->baliKocRounds()->max('round_number');
        $tournamentId = $tournament->id;
        $tournamentName = $tournament->name;

        // Отложенная персональная рассылка пушей — каждому игроку своё сообщение
        // с указанием корта и соперника. Не блокируем редирект.
        app()->terminating(function () use ($tournamentId, $tournamentName, $newRoundNumber) {
            self::notifyBaliRoundGenerated($tournamentId, $tournamentName, $newRoundNumber);
        });

        return back()->with('success', "Раунд {$newRoundNumber} сгенерирован! Игрокам отправлены персональные уведомления.");
    }

    /**
     * Персональная рассылка после генерации раунда: каждому игроку — его корт
     * и состав соперника. Записываем в колокольчик и шлём FCM.
     */
    protected static function notifyBaliRoundGenerated(int $tournamentId, string $tournamentName, int $roundNumber): void
    {
        $round = \App\Models\BaliKocRound::where('tournament_id', $tournamentId)
            ->where('round_number', $roundNumber)
            ->with(['matches.pair1.player1', 'matches.pair1.player2', 'matches.pair2.player1', 'matches.pair2.player2'])
            ->first();
        if (!$round) return;

        $fcm = app(\App\Services\FCMNotificationService::class);
        $now = now();
        $notifications = [];

        // Соберём всех получателей одним списком, чтобы за один запрос подгрузить device_tokens
        $userIds = [];
        foreach ($round->matches as $m) {
            foreach ([$m->pair1->player1_id, $m->pair1->player2_id, $m->pair2->player1_id, $m->pair2->player2_id] as $uid) {
                if ($uid) $userIds[] = (int) $uid;
            }
        }
        if (empty($userIds)) return;

        $tokensByUser = User::whereIn('id', $userIds)
            ->whereHas('deviceTokens')
            ->with('deviceTokens')
            ->get()
            ->mapWithKeys(fn($u) => [$u->id => $u->deviceTokens->pluck('token')->all()]);

        foreach ($round->matches as $m) {
            $courtNum = (int) $m->court_number;
            $title = "Следующий раунд — корт {$courtNum}";

            $pair1Names = trim(($m->pair1->player1->name ?? '?') . ' + ' . ($m->pair1->player2->name ?? '?'));
            $pair2Names = trim(($m->pair2->player1->name ?? '?') . ' + ' . ($m->pair2->player2->name ?? '?'));

            $bodyForPair1 = "Соперник: {$pair2Names}";
            $bodyForPair2 = "Соперник: {$pair1Names}";

            $payload = [
                'type' => 'tournament',
                'category' => 'tournament',
                'subtype' => 'bali_koc_round_generated',
                'tournament_id' => (string) $tournamentId,
                'round_number' => (string) $roundNumber,
                'court_number' => (string) $courtNum,
            ];

            // Игрокам пары 1
            foreach ([$m->pair1->player1_id, $m->pair1->player2_id] as $uid) {
                if (!$uid) continue;
                $tokens = $tokensByUser[$uid] ?? [];
                if (!empty($tokens)) {
                    $fcm->sendMulticastToTokens($tokens, $title, $bodyForPair1, $payload);
                }
                $notifications[] = [
                    'user_id' => $uid,
                    'title' => $title,
                    'body' => $bodyForPair1,
                    'type' => 'tournament',
                    'category' => 'tournament',
                    'data' => json_encode($payload),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Игрокам пары 2
            foreach ([$m->pair2->player1_id, $m->pair2->player2_id] as $uid) {
                if (!$uid) continue;
                $tokens = $tokensByUser[$uid] ?? [];
                if (!empty($tokens)) {
                    $fcm->sendMulticastToTokens($tokens, $title, $bodyForPair2, $payload);
                }
                $notifications[] = [
                    'user_id' => $uid,
                    'title' => $title,
                    'body' => $bodyForPair2,
                    'type' => 'tournament',
                    'category' => 'tournament',
                    'data' => json_encode($payload),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($notifications)) {
            Notification::insert($notifications);
        }
    }
}
