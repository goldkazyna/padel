<?php

namespace App\Services;

use App\Models\Tournament;
use Illuminate\Support\Facades\DB;

class TournamentResetService
{
    /** Удаляет сгенерированную сетку и возвращает турнир в набор (open). Участники сохраняются. */
    public function reset(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament) {
            switch ($tournament->type) {
                case 'americano':
                    // Явно удаляем americano_rounds для групп (cascade в SQLite не срабатывает
                    // при Eloquent mass-delete, только при DB-level FK cascade).
                    // Сначала раунды (cascade удалит матчи), потом группы (cascade удалит group_players).
                    $groupIds = $tournament->groups()->pluck('id');
                    if ($groupIds->isNotEmpty()) {
                        DB::table('americano_rounds')
                            ->whereIn('tournament_group_id', $groupIds)
                            ->delete();
                    }
                    $tournament->groups()->delete();
                    $tournament->playoffMatches()->delete();
                    break;

                case 'mexicano':
                    DB::table('mexicano_rounds')->where('tournament_id', $tournament->id)->delete(); // cascade: matches
                    DB::table('mexicano_players')->where('tournament_id', $tournament->id)->delete();
                    DB::table('mexicano_pair_history')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'team':
                    $tournament->teamGroups()->delete();          // cascade: standings, group_matches
                    $tournament->playoffMatches()->delete();
                    break;

                case 'king_of_court':
                    $tournament->kingOfCourtRounds()->delete();   // cascade: matches
                    DB::table('kingofcourt_players')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'bali_koc':
                    // bali_koc_matches FK references bali_koc_pairs — delete rounds first (cascade matches),
                    // then pairs.
                    $tournament->baliKocRounds()->delete();       // cascade: matches
                    DB::table('bali_koc_pairs')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'americano_flex':
                    $tournament->americanoFlexRounds()->delete(); // cascade: matches, byes
                    DB::table('americano_flex_pair_history')->where('tournament_id', $tournament->id)->delete();
                    DB::table('americano_flex_players')->where('tournament_id', $tournament->id)->delete();
                    break;

                default: // classic / legacy
                    $tournament->playoffMatches()->delete();
                    break;
            }

            $tournament->update(['status' => 'open']);
        });
    }
}
