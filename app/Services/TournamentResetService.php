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
                    $groupIds = DB::table('tournament_groups')->where('tournament_id', $tournament->id)->pluck('id');
                    $roundIds = DB::table('americano_rounds')->whereIn('tournament_group_id', $groupIds)->pluck('id');
                    DB::table('americano_matches')->whereIn('americano_round_id', $roundIds)->delete();
                    DB::table('americano_rounds')->whereIn('tournament_group_id', $groupIds)->delete();
                    DB::table('tournament_group_players')->whereIn('tournament_group_id', $groupIds)->delete();
                    DB::table('tournament_groups')->where('tournament_id', $tournament->id)->delete();
                    DB::table('tournament_playoff_matches')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'mexicano':
                    $roundIds = DB::table('mexicano_rounds')->where('tournament_id', $tournament->id)->pluck('id');
                    DB::table('mexicano_matches')->whereIn('mexicano_round_id', $roundIds)->delete();
                    DB::table('mexicano_rounds')->where('tournament_id', $tournament->id)->delete();
                    DB::table('mexicano_players')->where('tournament_id', $tournament->id)->delete();
                    DB::table('mexicano_pair_history')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'team':
                    $groupIds = DB::table('tournament_team_groups')->where('tournament_id', $tournament->id)->pluck('id');
                    DB::table('tournament_playoff_matches')->where('tournament_id', $tournament->id)->delete();
                    DB::table('tournament_group_matches')->whereIn('group_id', $groupIds)->delete();
                    DB::table('tournament_team_standings')->whereIn('group_id', $groupIds)->delete();
                    DB::table('tournament_team_groups')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'king_of_court':
                    $roundIds = DB::table('kingofcourt_rounds')->where('tournament_id', $tournament->id)->pluck('id');
                    DB::table('kingofcourt_matches')->whereIn('kingofcourt_round_id', $roundIds)->delete();
                    DB::table('kingofcourt_rounds')->where('tournament_id', $tournament->id)->delete();
                    DB::table('kingofcourt_players')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'bali_koc':
                    $roundIds = DB::table('bali_koc_rounds')->where('tournament_id', $tournament->id)->pluck('id');
                    DB::table('bali_koc_matches')->whereIn('bali_koc_round_id', $roundIds)->delete();
                    DB::table('bali_koc_rounds')->where('tournament_id', $tournament->id)->delete();
                    DB::table('bali_koc_pairs')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'just_padel_it':
                    $roundIds = DB::table('just_padel_it_rounds')->where('tournament_id', $tournament->id)->pluck('id');
                    DB::table('just_padel_it_matches')->whereIn('just_padel_it_round_id', $roundIds)->delete();
                    DB::table('just_padel_it_rounds')->where('tournament_id', $tournament->id)->delete();
                    DB::table('just_padel_it_pairs')->where('tournament_id', $tournament->id)->delete();
                    DB::table('just_padel_it_players')->where('tournament_id', $tournament->id)->delete();
                    break;

                case 'americano_flex':
                    $roundIds = DB::table('americano_flex_rounds')->where('tournament_id', $tournament->id)->pluck('id');
                    DB::table('americano_flex_matches')->whereIn('americano_flex_round_id', $roundIds)->delete();
                    DB::table('americano_flex_byes')->whereIn('americano_flex_round_id', $roundIds)->delete();
                    DB::table('americano_flex_rounds')->where('tournament_id', $tournament->id)->delete();
                    DB::table('americano_flex_pair_history')->where('tournament_id', $tournament->id)->delete();
                    DB::table('americano_flex_players')->where('tournament_id', $tournament->id)->delete();
                    break;

                default: // classic / legacy
                    DB::table('tournament_playoff_matches')->where('tournament_id', $tournament->id)->delete();
                    break;
            }

            $tournament->update(['status' => 'open']);
        });
    }
}
