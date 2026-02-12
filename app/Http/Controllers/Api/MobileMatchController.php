<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AmericanoMatch;
use App\Models\MexicanoMatch;
use App\Models\TournamentGroupMatch;
use App\Models\TournamentPlayoffMatch;
use App\Models\TournamentTeam;
use Illuminate\Http\Request;

class MobileMatchController extends Controller
{
    /**
     * История матчей
     * GET /api/mobile/matches/history
     */
    public function history(Request $request)
    {
        $user = $request->user();
        $perPage = min((int) $request->get('per_page', 20), 50);
        $page = max((int) $request->get('page', 1), 1);

        $matches = $this->getAllMatches($user);

        // Сортируем по дате (новые первыми)
        usort($matches, fn($a, $b) => $b['sort_date'] <=> $a['sort_date']);

        // Пагинация
        $total = count($matches);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $items = array_slice($matches, $offset, $perPage);

        // Убираем служебное поле sort_date
        $items = array_map(function ($m) {
            unset($m['sort_date']);
            return $m;
        }, $items);

        return response()->json([
            'success' => true,
            'matches' => array_values($items),
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    private function getAllMatches($user): array
    {
        $userId = $user->id;
        $matches = [];

        // Американо
        $americanoMatches = AmericanoMatch::where('status', 'completed')
            ->where(function ($q) use ($userId) {
                $q->where('team1_player1_id', $userId)
                  ->orWhere('team1_player2_id', $userId)
                  ->orWhere('team2_player1_id', $userId)
                  ->orWhere('team2_player2_id', $userId);
            })
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.group.tournament'])
            ->get();

        foreach ($americanoMatches as $match) {
            $tournament = $match->round->group->tournament ?? null;
            $matches[] = $this->formatPlayerMatch($match, $userId, 'americano', $tournament);
        }

        // Мексикано
        $mexicanoMatches = MexicanoMatch::where('status', 'completed')
            ->where(function ($q) use ($userId) {
                $q->where('team1_player1_id', $userId)
                  ->orWhere('team1_player2_id', $userId)
                  ->orWhere('team2_player1_id', $userId)
                  ->orWhere('team2_player2_id', $userId);
            })
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.tournament'])
            ->get();

        foreach ($mexicanoMatches as $match) {
            $tournament = $match->round->tournament ?? null;
            $matches[] = $this->formatPlayerMatch($match, $userId, 'mexicano', $tournament);
        }

        // Плей-офф американо/мексикано (по player_id)
        $playoffPlayerMatches = TournamentPlayoffMatch::where('status', 'completed')
            ->whereNotNull('team1_player1_id')
            ->where(function ($q) use ($userId) {
                $q->where('team1_player1_id', $userId)
                  ->orWhere('team1_player2_id', $userId)
                  ->orWhere('team2_player1_id', $userId)
                  ->orWhere('team2_player2_id', $userId);
            })
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'tournament'])
            ->get();

        foreach ($playoffPlayerMatches as $match) {
            $matches[] = $this->formatPlayerMatch($match, $userId, 'playoff', $match->tournament);
        }

        // Командный турнир
        $teamIds = TournamentTeam::where('player1_id', $userId)
            ->orWhere('player2_id', $userId)
            ->pluck('id');

        if ($teamIds->count() > 0) {
            // Групповой этап
            $groupMatches = TournamentGroupMatch::where('status', 'completed')
                ->where(function ($q) use ($teamIds) {
                    $q->whereIn('team1_id', $teamIds)
                      ->orWhereIn('team2_id', $teamIds);
                })
                ->with(['team1.player1', 'team1.player2', 'team2.player1', 'team2.player2', 'group.tournament'])
                ->get();

            foreach ($groupMatches as $match) {
                $matches[] = $this->formatTeamMatch($match, $userId, $teamIds, 'group', $match->group->tournament ?? null);
            }

            // Плей-офф командный (по team_id)
            $playoffTeamMatches = TournamentPlayoffMatch::where('status', 'completed')
                ->whereNull('team1_player1_id')
                ->where(function ($q) use ($teamIds) {
                    $q->whereIn('team1_id', $teamIds)
                      ->orWhereIn('team2_id', $teamIds);
                })
                ->with(['team1.player1', 'team1.player2', 'team2.player1', 'team2.player2', 'tournament'])
                ->get();

            foreach ($playoffTeamMatches as $match) {
                $matches[] = $this->formatTeamMatch($match, $userId, $teamIds, 'playoff', $match->tournament);
            }
        }

        return $matches;
    }

    /**
     * Форматировать матч с player_id полями (американо, мексикано, плей-офф американо/мексикано)
     */
    private function formatPlayerMatch($match, int $userId, string $format, $tournament): array
    {
        $isTeam1 = $match->team1_player1_id == $userId || $match->team1_player2_id == $userId;

        $myScore = $isTeam1 ? $match->team1_score : $match->team2_score;
        $oppScore = $isTeam1 ? $match->team2_score : $match->team1_score;

        $partner = $isTeam1
            ? ($match->team1_player1_id == $userId ? $match->team1Player2 : $match->team1Player1)
            : ($match->team2_player1_id == $userId ? $match->team2Player2 : $match->team2Player1);

        $opponents = $isTeam1
            ? [$match->team2Player1, $match->team2Player2]
            : [$match->team1Player1, $match->team1Player2];

        return [
            'id' => $match->id,
            'tournament_name' => $tournament->name ?? 'Турнир',
            'date' => $tournament->start_date?->format('Y-m-d') ?? $match->updated_at->format('Y-m-d'),
            'format' => $format,
            'result' => $myScore > $oppScore ? 'win' : 'loss',
            'score' => "{$myScore}:{$oppScore}",
            'partner' => $partner ? [
                'id' => $partner->id,
                'name' => $partner->name,
                'avatar' => $partner->avatar,
            ] : null,
            'opponents' => array_values(array_filter(array_map(fn($o) => $o ? [
                'id' => $o->id,
                'name' => $o->name,
                'avatar' => $o->avatar,
            ] : null, $opponents))),
            'sort_date' => $match->updated_at->timestamp,
        ];
    }

    /**
     * Форматировать командный матч (group, playoff team)
     */
    private function formatTeamMatch($match, int $userId, $teamIds, string $format, $tournament): array
    {
        $isTeam1 = $teamIds->contains($match->team1_id);

        $myTeam = $isTeam1 ? $match->team1 : $match->team2;
        $oppTeam = $isTeam1 ? $match->team2 : $match->team1;

        $myScore = $isTeam1 ? $match->team1_score : $match->team2_score;
        $oppScore = $isTeam1 ? $match->team2_score : $match->team1_score;

        $partner = $myTeam->player1_id == $userId ? $myTeam->player2 : $myTeam->player1;

        return [
            'id' => $match->id,
            'tournament_name' => $tournament->name ?? 'Турнир',
            'date' => $tournament->start_date?->format('Y-m-d') ?? $match->updated_at->format('Y-m-d'),
            'format' => $format,
            'result' => $myScore > $oppScore ? 'win' : 'loss',
            'score' => "{$myScore}:{$oppScore}",
            'partner' => $partner ? [
                'id' => $partner->id,
                'name' => $partner->name,
                'avatar' => $partner->avatar,
            ] : null,
            'opponents' => array_values(array_filter([
                $oppTeam->player1 ? [
                    'id' => $oppTeam->player1->id,
                    'name' => $oppTeam->player1->name,
                    'avatar' => $oppTeam->player1->avatar,
                ] : null,
                $oppTeam->player2 ? [
                    'id' => $oppTeam->player2->id,
                    'name' => $oppTeam->player2->name,
                    'avatar' => $oppTeam->player2->avatar,
                ] : null,
            ])),
            'sort_date' => $match->updated_at->timestamp,
        ];
    }
}
