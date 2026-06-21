<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\AmericanoFlexMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Services\AmericanoFlexService;
use Illuminate\Http\Request;

class AmericanoFlexController extends Controller
{
    public function __construct(private AmericanoFlexService $service) {}

    /** POST /club/tournaments/{tournament}/flex/start */
    public function start(Tournament $tournament)
    {
        if (!$tournament->isAmericanoFlex()) {
            return back()->with('error', 'Этот турнир не Americano Flex');
        }
        if ($tournament->status !== 'open' && $tournament->status !== 'closed') {
            return back()->with('error', 'Турнир уже запущен или завершён');
        }

        // Парный флекс: должны быть собраны все пары (по 2 игрока).
        if ($tournament->isPairedFlex()) {
            $pairs = $tournament->teams()->whereNotNull('player2_id')->count();
            $needPairs = (int) ($tournament->max_participants / 2);
            if ($pairs < $needPairs) {
                return back()->with('error', "Сначала соберите все пары: {$pairs} из {$needPairs}.");
            }
            if (!$this->service->startTournament($tournament)) {
                return back()->with('error', 'Не удалось запустить: недостаточно пар.');
            }
            return back()->with('success', 'Турнир запущен, первый раунд сгенерирован');
        }

        // Spec §4: минимум игроков для Flex = courts_count × 4 (нужен хотя бы 1 в очереди для смысла,
        // но математически Flex работает и при N = M×4 — это будет вырожденный Mexicano).
        $registered = TournamentParticipant::where('tournament_id', $tournament->id)
            ->where('status', 'registered')
            ->count();
        $minRequired = max(4, $tournament->courts_count * 4);
        if ($registered < $minRequired) {
            return back()->with('error', "Недостаточно зарегистрированных игроков: {$registered}. Минимум для {$tournament->courts_count} корта/кортов — {$minRequired}.");
        }

        $this->service->startTournament($tournament);
        return back()->with('success', 'Турнир запущен, первый раунд сгенерирован');
    }

    /** POST /club/tournaments/{tournament}/flex/next-round */
    public function nextRound(Tournament $tournament)
    {
        $current = $this->service->getCurrentRound($tournament);
        if ($current && !$this->service->isRoundCompleted($current)) {
            return back()->with('error', 'Текущий раунд ещё не завершён');
        }

        $this->service->generateNextRound($tournament);
        return back()->with('success', 'Следующий раунд сгенерирован');
    }

    /** POST /club/tournaments/{tournament}/flex/complete */
    public function complete(Tournament $tournament)
    {
        $this->service->completeTournament($tournament);
        return back()->with('success', 'Турнир завершён, рейтинги обновлены');
    }

    /** POST /club/tournaments/flex/matches/{match}/score */
    public function saveScore(Request $request, AmericanoFlexMatch $match)
    {
        $data = $request->validate([
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);
        $this->service->saveMatchResult($match, $data['team1_score'], $data['team2_score']);
        return back()->with('success', 'Счёт сохранён');
    }

    /** PUT /club/tournaments/flex/matches/{match}/score */
    public function updateScore(Request $request, AmericanoFlexMatch $match)
    {
        return $this->saveScore($request, $match);
    }
}
