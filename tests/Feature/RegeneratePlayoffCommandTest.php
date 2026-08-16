<?php

namespace Tests\Feature;

use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\TournamentPlayoffMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пересборка плей-офф без потери группового этапа.
 *
 * Нужна, когда сетку строят заново по уже сыгранным группам — например,
 * после смены правил посева.
 */
class RegeneratePlayoffCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Американо на три группы с доигранным групповым этапом. */
    private function finishedGroups(): Tournament
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);
        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano',
            'status' => 'in_progress',
            'groups_count' => 3,
            'max_participants' => 12,
            'rounds_count' => 1,
            'has_playoff' => true,
            'playoff_type' => 'semifinal_final',
            'playoff_format' => Tournament::PLAYOFF_FORMAT_TABLE_QF,
        ]);

        for ($g = 0; $g < 3; $g++) {
            $group = TournamentGroup::create([
                'tournament_id' => $tournament->id,
                'name' => 'Группа ' . chr(65 + $g),
            ]);
            $players = [];
            for ($p = 0; $p < 4; $p++) {
                $index = $g * 4 + $p;
                $user = User::factory()->create(['name' => 'И' . ($index + 1), 'rating' => 1500]);
                $group->players()->attach($user->id, [
                    'total_points' => 100 - $index,
                    'rating_before' => 1500,
                    'rating_after' => null,
                ]);
                $players[] = $user;
            }

            $round = AmericanoRound::create([
                'tournament_group_id' => $group->id,
                'round_number' => 1,
                'status' => 'completed',
            ]);
            AmericanoMatch::create([
                'americano_round_id' => $round->id,
                'court_number' => $g + 1,
                'team1_player1_id' => $players[0]->id,
                'team1_player2_id' => $players[1]->id,
                'team2_player1_id' => $players[2]->id,
                'team2_player2_id' => $players[3]->id,
                'team1_score' => 21,
                'team2_score' => 15,
                'status' => 'completed',
            ]);
        }

        return $tournament->fresh();
    }

    public function test_rebuilds_bracket_and_keeps_group_scores(): void
    {
        $t = $this->finishedGroups();

        // Старая сетка: пусть будет один посторонний матч, чтобы увидеть замену.
        TournamentPlayoffMatch::create([
            'tournament_id' => $t->id,
            'stage' => 'Четвертьфинал',
            'bracket' => 'upper',
            'match_number' => 1,
            'status' => 'pending',
        ]);

        $this->artisan('tournament:regenerate-playoff', ['id' => $t->id])
            ->assertSuccessful();

        $quarters = TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Четвертьфинал')->get();

        $this->assertCount(2, $quarters, 'сетка собрана заново');
        $this->assertNotNull($quarters->first()->team1_player1_id, 'пары заполнены');

        // Групповой этап нетронут: счёт вносить заново не надо.
        $this->assertSame(3, AmericanoMatch::where('status', 'completed')->count());
        $this->assertSame(
            100,
            (int) $t->groups()->first()->players()->first()->pivot->total_points,
            'очки в группах на месте'
        );
    }

    public function test_asks_before_dropping_played_playoff_matches(): void
    {
        $t = $this->finishedGroups();
        TournamentPlayoffMatch::create([
            'tournament_id' => $t->id,
            'stage' => 'Четвертьфинал', 'bracket' => 'upper', 'match_number' => 1,
            'team1_score' => 21, 'team2_score' => 15, 'status' => 'completed',
        ]);

        $this->artisan('tournament:regenerate-playoff', ['id' => $t->id])
            ->expectsConfirmation('Счёт 1 матчей плей-офф будет потерян. Продолжить?', 'no')
            ->assertSuccessful();

        $this->assertSame(1, TournamentPlayoffMatch::where('tournament_id', $t->id)->count(),
            'отказ оставляет всё как было');
    }

    public function test_force_skips_the_question(): void
    {
        $t = $this->finishedGroups();
        TournamentPlayoffMatch::create([
            'tournament_id' => $t->id,
            'stage' => 'Четвертьфинал', 'bracket' => 'upper', 'match_number' => 1,
            'team1_score' => 21, 'team2_score' => 15, 'status' => 'completed',
        ]);

        $this->artisan('tournament:regenerate-playoff', ['id' => $t->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(2, TournamentPlayoffMatch::where('tournament_id', $t->id)
            ->where('stage', 'Четвертьфинал')->count());
    }

    public function test_unknown_tournament_is_reported(): void
    {
        $this->artisan('tournament:regenerate-playoff', ['id' => 999999])
            ->assertFailed();
    }

    public function test_unsupported_format_is_refused(): void
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);
        $t = Tournament::factory()->create(['club_id' => $club->id, 'type' => 'escalera']);

        $this->artisan('tournament:regenerate-playoff', ['id' => $t->id])
            ->assertFailed();
    }
}
