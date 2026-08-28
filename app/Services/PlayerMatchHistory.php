<?php

namespace App\Services;

use App\Models\AmericanoFlexMatch;
use App\Models\AmericanoMatch;
use App\Models\BaliKocMatch;
use App\Models\BaliKocPair;
use App\Models\JustPadelItMatch;
use App\Models\KingOfCourtMatch;
use App\Models\MexicanoMatch;
use App\Models\RoundRobinMatch;
use App\Models\TournamentGroupMatch;
use App\Models\TournamentPlayoffMatch;
use App\Models\TournamentTeam;
use App\Models\User;

/**
 * История сыгранных матчей игрока по всем форматам.
 *
 * Живёт отдельно от контроллера, потому что этим же пользуются достижения.
 * Наружу в приложение уходит обрезанный вид, а внутри у каждой записи есть
 * турнир, его тип и клуб — без них форматы и «без потерь» не посчитать.
 */
class PlayerMatchHistory
{
    public function for(User $user): array
    {
        $userId = $user->id;
        $matches = [];

        // Американо
        $americanoMatches = AmericanoMatch::where('status', 'completed')
            ->whereHas('round.group.tournament', fn ($q) => $q->where('status', 'completed'))
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
            ->whereHas('round.tournament', fn ($q) => $q->where('status', 'completed'))
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

        // Americano Flex
        $flexMatches = AmericanoFlexMatch::where('status', 'completed')
            ->whereHas('round.tournament', fn ($q) => $q->where('status', 'completed'))
            ->where(function ($q) use ($userId) {
                $q->where('team1_player1_id', $userId)
                  ->orWhere('team1_player2_id', $userId)
                  ->orWhere('team2_player1_id', $userId)
                  ->orWhere('team2_player2_id', $userId);
            })
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.tournament'])
            ->get();

        foreach ($flexMatches as $match) {
            $tournament = $match->round->tournament ?? null;
            $matches[] = $this->formatPlayerMatch($match, $userId, 'americano_flex', $tournament);
        }

        // Round Robin
        $roundRobinMatches = RoundRobinMatch::where('status', 'completed')
            ->whereHas('round.tournament', fn ($q) => $q->where('status', 'completed'))
            ->where(function ($q) use ($userId) {
                $q->where('team1_player1_id', $userId)
                  ->orWhere('team1_player2_id', $userId)
                  ->orWhere('team2_player1_id', $userId)
                  ->orWhere('team2_player2_id', $userId);
            })
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.tournament'])
            ->get();

        foreach ($roundRobinMatches as $match) {
            $tournament = $match->round->tournament ?? null;
            $matches[] = $this->formatPlayerMatch($match, $userId, 'round_robin', $tournament);
        }

        // Король корта (King of Court)
        $kocMatches = KingOfCourtMatch::where('status', 'completed')
            ->whereHas('round.tournament', fn ($q) => $q->where('status', 'completed'))
            ->where(function ($q) use ($userId) {
                $q->where('team1_player1_id', $userId)
                  ->orWhere('team1_player2_id', $userId)
                  ->orWhere('team2_player1_id', $userId)
                  ->orWhere('team2_player2_id', $userId);
            })
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.tournament'])
            ->get();

        foreach ($kocMatches as $match) {
            $tournament = $match->round->tournament ?? null;
            $matches[] = $this->formatPlayerMatch($match, $userId, 'king_of_court', $tournament);
        }

        // Ladder: путь до турнира длиннее — матч висит на корте раунда.
        $escaleraMatches = \App\Models\EscaleraMatch::where('status', 'completed')
            ->whereHas('court.round.tournament', fn ($q) => $q->where('status', 'completed'))
            ->where(function ($q) use ($userId) {
                $q->where('team1_player1_id', $userId)
                  ->orWhere('team1_player2_id', $userId)
                  ->orWhere('team2_player1_id', $userId)
                  ->orWhere('team2_player2_id', $userId);
            })
            ->with([
                'team1Player1', 'team1Player2', 'team2Player1', 'team2Player2',
                'court.round.tournament',
            ])
            ->get();

        foreach ($escaleraMatches as $match) {
            $tournament = $match->court?->round?->tournament;
            $matches[] = $this->formatPlayerMatch($match, $userId, 'escalera', $tournament);
        }

        // Just Padel It (player-based, как King of Court)
        $jpiMatches = JustPadelItMatch::where('status', 'completed')
            ->whereHas('round.tournament', fn ($q) => $q->where('status', 'completed'))
            ->where(function ($q) use ($userId) {
                $q->where('team1_player1_id', $userId)
                  ->orWhere('team1_player2_id', $userId)
                  ->orWhere('team2_player1_id', $userId)
                  ->orWhere('team2_player2_id', $userId);
            })
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.tournament'])
            ->get();

        foreach ($jpiMatches as $match) {
            $tournament = $match->round->tournament ?? null;
            $matches[] = $this->formatPlayerMatch($match, $userId, 'just_padel_it', $tournament);
        }

        // Bali King of Court (пары: pair1/pair2, счёт по геймам)
        $baliPairIds = BaliKocPair::where('player1_id', $userId)
            ->orWhere('player2_id', $userId)
            ->pluck('id');
        if ($baliPairIds->count() > 0) {
            $baliMatches = BaliKocMatch::where('status', 'completed')
                ->whereHas('round.tournament', fn ($q) => $q->where('status', 'completed'))
                ->where(function ($q) use ($baliPairIds) {
                    $q->whereIn('pair1_id', $baliPairIds)->orWhereIn('pair2_id', $baliPairIds);
                })
                ->with(['pair1.player1', 'pair1.player2', 'pair2.player1', 'pair2.player2', 'round.tournament'])
                ->get();

            foreach ($baliMatches as $match) {
                $matches[] = $this->formatBaliMatch($match, $userId, $baliPairIds);
            }
        }

        // Плей-офф американо/мексикано (по player_id)
        $playoffPlayerMatches = TournamentPlayoffMatch::where('status', 'completed')
            ->whereHas('tournament', fn ($q) => $q->where('status', 'completed'))
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
                ->whereHas('group.tournament', fn ($q) => $q->where('status', 'completed'))
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
                ->whereHas('tournament', fn ($q) => $q->where('status', 'completed'))
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
            'tournament_id' => $tournament?->id,
            'tournament_type' => $tournament?->type,
            'club_id' => $tournament?->club_id,
            'date' => $tournament->start_date?->format('Y-m-d') ?? $match->updated_at->format('Y-m-d'),
            'format' => $format,
            'result' => $myScore > $oppScore ? 'win' : ($myScore < $oppScore ? 'loss' : 'draw'),
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
     * Форматировать матч Bali KOC (пары pair1/pair2, счёт по геймам).
     */
    private function formatBaliMatch($match, int $userId, $pairIds): array
    {
        $isPair1 = $pairIds->contains($match->pair1_id);

        $myScore = $isPair1 ? $match->pair1_games : $match->pair2_games;
        $oppScore = $isPair1 ? $match->pair2_games : $match->pair1_games;

        $myPair = $isPair1 ? $match->pair1 : $match->pair2;
        $oppPair = $isPair1 ? $match->pair2 : $match->pair1;

        $partner = $myPair
            ? ($myPair->player1_id == $userId ? $myPair->player2 : $myPair->player1)
            : null;
        $opponents = $oppPair ? [$oppPair->player1, $oppPair->player2] : [];

        $tournament = $match->round->tournament ?? null;

        return [
            'id' => $match->id,
            'tournament_name' => $tournament->name ?? 'Турнир',
            'tournament_id' => $tournament?->id,
            'tournament_type' => $tournament?->type,
            'club_id' => $tournament?->club_id,
            'date' => $tournament->start_date?->format('Y-m-d') ?? $match->updated_at->format('Y-m-d'),
            'format' => 'bali_koc',
            'result' => $myScore > $oppScore ? 'win' : ($myScore < $oppScore ? 'loss' : 'draw'),
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
            'tournament_id' => $tournament?->id,
            'tournament_type' => $tournament?->type,
            'club_id' => $tournament?->club_id,
            'date' => $tournament->start_date?->format('Y-m-d') ?? $match->updated_at->format('Y-m-d'),
            'format' => $format,
            'result' => $myScore > $oppScore ? 'win' : ($myScore < $oppScore ? 'loss' : 'draw'),
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
