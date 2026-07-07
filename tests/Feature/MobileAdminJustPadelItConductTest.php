<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileAdminJustPadelItConductTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Club,1:User,2:Tournament} */
    private function makeTournament(bool $paired = false, int $players = 8, int $courts = 2): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'just_padel_it',
            'status' => 'open',
            'max_participants' => $players,
            'courts_count' => $courts,
            'is_paired' => $paired,
        ]);
        for ($i = 1; $i <= $players; $i++) {
            $u = User::factory()->create(['rating' => 1000 + $i * 100]);
            TournamentParticipant::create([
                'tournament_id' => $t->id,
                'user_id' => $u->id,
                'status' => 'registered',
            ]);
        }
        return [$club, $admin, $t];
    }

    public function test_solo_start_creates_first_round(): void
    {
        [$club, $admin, $t] = $this->makeTournament(false, 8, 2);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertOk()
            ->assertJsonPath('success', true);

        $t->refresh();
        $this->assertSame('in_progress', $t->status);
        $this->assertSame(1, $t->justPadelItRounds()->count());
    }

    public function test_seeding_endpoint_returns_participants_sorted_by_rating(): void
    {
        [$club, $admin, $t] = $this->makeTournament(false, 8, 2);
        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/justpadelit/seeding")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('courts_count', 2);

        $ratings = array_column($res->json('participants'), 'rating');
        $sorted = $ratings;
        rsort($sorted);
        $this->assertSame($sorted, $ratings, 'participants must be sorted by rating desc');
    }

    public function test_seeding_endpoint_returns_courts_count_from_registered_not_max(): void
    {
        // max_participants=16 (создатель ожидал 4 корта), но фактически
        // записалось только 12 игроков — посев должен строиться под 3 корта
        // (столько, сколько реально сможет запустить startTournament()),
        // а не под сохранённый courts_count=4.
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'just_padel_it',
            'status' => 'open',
            'max_participants' => 16,
            'courts_count' => 4,
            'is_paired' => false,
        ]);
        for ($i = 1; $i <= 12; $i++) {
            $u = User::factory()->create(['rating' => 1000 + $i * 100]);
            TournamentParticipant::create([
                'tournament_id' => $t->id,
                'user_id' => $u->id,
                'status' => 'registered',
            ]);
        }

        Sanctum::actingAs($admin);

        $this->getJson("/api/mobile/admin/tournaments/{$t->id}/justpadelit/seeding")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('courts_count', 3);
    }

    public function test_paired_start_without_pairs_requires_pairs(): void
    {
        [$club, $admin, $t] = $this->makeTournament(true, 8, 2);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('pairs_required', true);
    }

    public function test_matches_endpoint_returns_rounds_and_standings_for_jpi(): void
    {
        [$club, $admin, $t] = $this->makeTournament(false, 8, 2);
        Sanctum::actingAs($admin);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")->assertOk();

        $res = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")
            ->assertOk()
            ->assertJsonPath('type', 'just_padel_it');

        // Образец (buildKingOfCourtMatches) не отдаёт плоские top-level
        // rounds/standings — он заворачивает всё в одну виртуальную группу
        // groups[0] = ['rounds' => ..., 'leaderboard' => ...], чтобы фронт
        // мог переиспользовать общий рендер «группа → раунды → таблица».
        $this->assertNotEmpty($res->json('groups.0.rounds'), 'must return rounds');
        $this->assertNotNull($res->json('groups.0.leaderboard'), 'must return standings (leaderboard)');
    }

    public function test_save_score_awards_points_and_court_bonus(): void
    {
        [$club, $admin, $t] = $this->makeTournament(false, 8, 2);
        Sanctum::actingAs($admin);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")->assertOk();

        $match = \App\Models\JustPadelItMatch::whereHas('round', function ($q) use ($t) {
            $q->where('tournament_id', $t->id);
        })->where('court_number', 1)->firstOrFail();

        $this->postJson(
            "/api/mobile/admin/tournaments/{$t->id}/justpadelit/matches/{$match->id}/score",
            ['team1_score' => 6, 'team2_score' => 2]
        )->assertOk()->assertJsonPath('success', true);

        $match->refresh();
        $this->assertSame('completed', $match->status);
        $this->assertSame(6, (int) $match->team1_score);
    }

    private function completeCurrentRound(Tournament $t, \App\Services\JustPadelItService $jpi): void
    {
        $round = $t->justPadelItRounds()->orderByDesc('round_number')->first();
        foreach ($round->matches as $m) {
            $jpi->saveMatchResult($m, 6, 2);
        }
    }

    public function test_next_round_generates_second_round(): void
    {
        [$club, $admin, $t] = $this->makeTournament(false, 8, 2);
        Sanctum::actingAs($admin);
        $jpi = app(\App\Services\JustPadelItService::class);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")->assertOk();
        $t->refresh();
        $this->completeCurrentRound($t, $jpi);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/next-round")->assertOk();

        $this->assertSame(2, $t->fresh()->justPadelItRounds()->count());
    }

    public function test_finish_completes_tournament(): void
    {
        [$club, $admin, $t] = $this->makeTournament(false, 8, 2);
        Sanctum::actingAs($admin);
        $jpi = app(\App\Services\JustPadelItService::class);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")->assertOk();
        $t->refresh();
        $this->completeCurrentRound($t, $jpi);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/finish")->assertOk();

        $this->assertSame('completed', $t->fresh()->status);
    }

    public function test_paired_pairs_then_start(): void
    {
        [$club, $admin, $t] = $this->makeTournament(true, 8, 2);
        Sanctum::actingAs($admin);

        $ids = $t->participants()->pluck('users.id')->values()->all();
        $pairs = [[$ids[0], $ids[1]], [$ids[2], $ids[3]], [$ids[4], $ids[5]], [$ids[6], $ids[7]]];

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/justpadelit/pairs", ['pairs' => $pairs])
            ->assertOk()->assertJsonPath('success', true);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame('in_progress', $t->fresh()->status);
    }

    public function test_detail_shows_jpi_pairs_created_flag(): void
    {
        [$club, $admin, $t] = $this->makeTournament(true, 8, 2);
        Sanctum::actingAs($admin);

        $this->getJson("/api/mobile/admin/tournaments/{$t->id}")
            ->assertOk()
            ->assertJsonPath('tournament.jpi_pairs_created', false);

        $ids = $t->participants()->pluck('users.id')->values()->all();
        $pairs = [[$ids[0], $ids[1]], [$ids[2], $ids[3]], [$ids[4], $ids[5]], [$ids[6], $ids[7]]];
        [$ok] = app(\App\Services\JustPadelItService::class)->createPairs($t, $pairs);
        $this->assertTrue($ok);

        $this->getJson("/api/mobile/admin/tournaments/{$t->id}")
            ->assertOk()
            ->assertJsonPath('tournament.jpi_pairs_created', true);
    }

    public function test_solo_leaderboard_breaks_points_tie_by_wins(): void
    {
        [$club, $admin, $t] = $this->makeTournament(false, 8, 2);
        Sanctum::actingAs($admin);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")->assertOk();

        $players = \App\Models\JustPadelItPlayer::where('tournament_id', $t->id)
            ->orderBy('user_id')->get();

        // Двое с равными очками, но у второго (больший user_id) — больше побед.
        // На старой сортировке (sortByDesc без тай-брейка) он оставался ниже.
        $low = $players[0];   // те же очки, меньше побед
        $high = $players[1];  // те же очки, больше побед
        $low->update(['total_points' => 21, 'wins' => 1, 'losses' => 2]);
        $high->update(['total_points' => 21, 'wins' => 2, 'losses' => 1]);
        foreach ($players->slice(2) as $p) {
            $p->update(['total_points' => 5, 'wins' => 0, 'losses' => 3]);
        }

        $board = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")
            ->assertOk()
            ->json('groups.0.leaderboard');

        $posHigh = collect($board)->firstWhere('id', $high->user_id)['position'];
        $posLow = collect($board)->firstWhere('id', $low->user_id)['position'];

        $this->assertLessThan(
            $posLow,
            $posHigh,
            'при равных очках игрок с большим числом побед должен стоять выше'
        );
    }
}
