<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Мобильная админка «Эскалеры»: создание, старт, матчи, счёт, раунды, финиш.
 */
class MobileAdminEscaleraTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Club,1:User} */
    private function makeClubAdmin(): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        return [$club, $admin];
    }

    public function test_create_sets_participants_from_courts(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'type' => 'escalera',
            'name' => 'Эскалера вечер',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1,
            'max_level' => 5.75,
            // Намеренно неверное число: сервер считает участников сам.
            'max_participants' => 30,
            'status' => 'open',
            'courts_count' => 4,
            'escalera_standings_mode' => 'raw_points',
        ])->assertOk()->assertJsonPath('success', true);

        $t = Tournament::where('name', 'Эскалера вечер')->firstOrFail();
        $this->assertSame('escalera', $t->type);
        $this->assertSame(4, (int) $t->courts_count);
        $this->assertSame(16, (int) $t->max_participants, 'участников ровно кортов × 4');
        $this->assertSame('raw_points', $t->escalera_standings_mode);
    }

    public function test_create_defaults_standings_mode_to_raw_points(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'type' => 'escalera',
            'name' => 'Эскалера без режима',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1,
            'max_level' => 5.75,
            'max_participants' => 12,
            'status' => 'open',
            'courts_count' => 3,
        ])->assertOk();

        $t = Tournament::where('name', 'Эскалера без режима')->firstOrFail();
        $this->assertSame('raw_points', $t->escalera_standings_mode, 'по умолчанию зачёт по очкам');
        $this->assertSame(12, (int) $t->max_participants);
    }

    public function test_create_rejects_courts_out_of_range(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'type' => 'escalera',
            'name' => 'Эскалера один корт',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1,
            'max_level' => 5.75,
            'max_participants' => 4,
            'status' => 'open',
            'courts_count' => 1,
        ])->assertStatus(422);
    }

    /**
     * Готовый к старту турнир: кортов × 4 зарегистрированных игрока.
     *
     * @return array{0:Club,1:User,2:Tournament}
     */
    private function makeReadyTournament(int $courts = 3): array
    {
        [$club, $admin] = $this->makeClubAdmin();

        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'escalera',
            'status' => 'open',
            'courts_count' => $courts,
            'max_participants' => $courts * 4,
            'escalera_standings_mode' => 'points',
            'start_date' => now()->addDay(),
        ]);

        for ($i = 1; $i <= $courts * 4; $i++) {
            $user = User::factory()->create(['rating' => 1000 + $i * 50]);
            TournamentParticipant::create([
                'tournament_id' => $t->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
        }

        return [$club, $admin, $t];
    }

    public function test_start_creates_first_round(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertOk()
            ->assertJsonPath('success', true);

        $t->refresh();
        $this->assertSame('in_progress', $t->status);
        $this->assertSame(1, $t->escaleraRounds()->count());
        $this->assertSame(3, $t->escaleraRounds()->first()->courts()->count());
        $this->assertSame(12, $t->escaleraPlayers()->count());
    }

    public function test_start_blocked_when_participants_do_not_match_courts(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        // Убираем одного игрока — двенадцати уже нет.
        TournamentParticipant::where('tournament_id', $t->id)->first()->delete();
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('open', $t->fresh()->status);
    }

    public function test_update_recalculates_participants_before_start(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        Sanctum::actingAs($admin);

        // Дату не меняем: её правка рассылает участникам пуши, а нас здесь
        // интересует только пересчёт участников из кортов.
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", [
            'name' => $t->name,
            'start_date' => $t->start_date->toIso8601String(),
            'min_level' => 1,
            'max_level' => 5.75,
            'max_participants' => 99,
            'status' => 'open',
            'courts_count' => 5,
        ])->assertOk();

        $t->refresh();
        $this->assertSame(5, (int) $t->courts_count);
        $this->assertSame(20, (int) $t->max_participants);
    }

    public function test_matches_returns_rounds_courts_and_leaderboard(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('type', 'escalera')
            ->assertJsonPath('playoff', null)
            ->assertJsonPath('summary.matches_total', 9)
            ->assertJsonPath('summary.matches_played', 0)
            ->assertJsonPath('summary.can_generate_next_round', false)
            ->assertJsonPath('summary.can_finish', false);

        $rounds = $res->json('groups.0.rounds');
        $this->assertCount(1, $rounds);
        $this->assertSame(1, $rounds[0]['round_number']);
        $this->assertCount(9, $rounds[0]['matches'], 'три корта по три матча');

        // Матчи несут номер корта — по нему приложение группирует карточки.
        $courts = array_unique(array_column($rounds[0]['matches'], 'court_number'));
        sort($courts);
        $this->assertSame([1, 2, 3], $courts);

        // В матче обе пары по два игрока с именами.
        $first = $rounds[0]['matches'][0];
        $this->assertCount(2, $first['team1']['players']);
        $this->assertCount(2, $first['team2']['players']);
        $this->assertNotEmpty($first['team1']['players'][0]['name']);

        $leaderboard = $res->json('groups.0.leaderboard');
        $this->assertCount(12, $leaderboard);
    }

    public function test_matches_leaderboard_carries_scored_and_conceded(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        $service = app(\App\Services\EscaleraService::class);
        $service->startTournament($t);

        // Первый корт: 12:0, 11:1, 10:2 — у первой посадки 33 забитых и 3 пропущенных.
        $court = $t->fresh()->escaleraRounds()->first()->courts()->orderBy('court_number')->first();
        $scores = [[12, 0], [11, 1], [10, 2]];
        foreach ($court->matches()->orderBy('match_number')->get() as $i => $match) {
            $service->saveMatchResult($match, $scores[$i][0], $scores[$i][1]);
        }
        $seatingFirst = $court->playerIds()[0];

        Sanctum::actingAs($admin);
        $res = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")->assertOk();

        $row = collect($res->json('groups.0.leaderboard'))->firstWhere('id', $seatingFirst);

        $this->assertSame(33, $row['points_for'], 'забито');
        $this->assertSame(3, $row['points_against'], 'пропущено');
        $this->assertSame(3, $row['wins']);
        $this->assertSame(0, $row['losses']);
        $this->assertSame(92, $row['ball_percent'], '33 из 36');
    }

    public function test_matches_flags_ready_round(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        $service = app(\App\Services\EscaleraService::class);
        $service->startTournament($t);

        // Все счета внесены — можно и генерировать следующий раунд, и завершать.
        foreach ($t->fresh()->escaleraRounds()->first()->courts as $court) {
            foreach ($court->matches()->orderBy('match_number')->get() as $match) {
                $service->saveMatchResult($match, 7, 5);
            }
        }

        Sanctum::actingAs($admin);
        $this->getJson("/api/mobile/admin/tournaments/{$t->id}/matches")
            ->assertOk()
            ->assertJsonPath('summary.matches_played', 9)
            ->assertJsonPath('summary.can_generate_next_round', true)
            ->assertJsonPath('summary.can_finish', true);
    }

    public function test_save_score_accepts_any_sum_and_draw(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $match = $t->fresh()->escaleraRounds()->first()->courts()->first()->matches()->first();
        Sanctum::actingAs($admin);

        // Свободный счёт: сумма ничем не ограничена.
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$match->id}/score", [
            'team1_score' => 12,
            'team2_score' => 10,
        ])->assertOk()->assertJsonPath('success', true);

        $match->refresh();
        $this->assertSame(12, (int) $match->team1_score);
        $this->assertSame('completed', $match->status);

        // Ничья допустима — в эскалере она не победа и не поражение.
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$match->id}/score", [
            'team1_score' => 6,
            'team2_score' => 6,
        ])->assertOk();

        $this->assertSame(6, (int) $match->fresh()->team2_score);
    }

    public function test_save_score_rejects_negative(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $match = $t->fresh()->escaleraRounds()->first()->courts()->first()->matches()->first();
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$match->id}/score", [
            'team1_score' => -1,
            'team2_score' => 5,
        ])->assertStatus(422);

        $this->assertNull($match->fresh()->team1_score);
    }

    public function test_save_score_rejects_match_from_other_tournament(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);

        [$otherClub, $otherAdmin, $other] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($other);
        $foreign = $other->fresh()->escaleraRounds()->first()->courts()->first()->matches()->first();

        Sanctum::actingAs($admin);
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$foreign->id}/score", [
            'team1_score' => 7,
            'team2_score' => 5,
        ])->assertStatus(404);

        $this->assertNull($foreign->fresh()->team1_score);
    }

    public function test_save_score_forbidden_for_foreign_admin(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $match = $t->fresh()->escaleraRounds()->first()->courts()->first()->matches()->first();

        $stranger = User::factory()->create(['role' => 'club_admin']);
        Sanctum::actingAs($stranger);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/escalera/matches/{$match->id}/score", [
            'team1_score' => 7,
            'team2_score' => 5,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->team1_score);
    }

    /** Внести счёт во все матчи текущего раунда. */
    private function playCurrentRound(Tournament $tournament): void
    {
        $service = app(\App\Services\EscaleraService::class);
        $round = $tournament->fresh()->escaleraRounds()->reorder('round_number', 'desc')->first();

        foreach ($round->courts as $court) {
            $scores = [[12, 0], [11, 1], [10, 2]];
            foreach ($court->matches()->orderBy('match_number')->get() as $i => $match) {
                $service->saveMatchResult($match, $scores[$i][0], $scores[$i][1]);
            }
        }
    }

    public function test_next_round_closes_current_and_creates_next(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $this->playCurrentRound($t);
        Sanctum::actingAs($admin);

        $res = $this->postJson("/api/mobile/admin/tournaments/{$t->id}/next-round")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('type', 'escalera');

        $t->refresh();
        $this->assertSame(2, $t->escaleraRounds()->count(), 'следующий раунд создан');
        $this->assertTrue(
            $t->escaleraRounds()->where('round_number', 1)->first()->isCompleted(),
            'первый раунд закрыт'
        );

        // Ответ уже содержит оба раунда — приложение не делает второй запрос.
        $this->assertCount(2, $res->json('groups.0.rounds'));
    }

    public function test_next_round_blocked_until_scores_entered(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/next-round")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, $t->fresh()->escaleraRounds()->count());
    }

    public function test_finish_closes_open_round_and_awards_rating(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        $this->playCurrentRound($t);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/finish")
            ->assertOk()
            ->assertJsonPath('success', true);

        $t->refresh();
        $this->assertSame('completed', $t->status);
        $this->assertSame(1, $t->escaleraRounds()->count(), 'лишний раунд не создан');
        $this->assertTrue($t->escaleraRounds()->first()->isCompleted());

        // Рейтинг начислен: у каждого игрока проставлен rating_after.
        $this->assertSame(0, $t->escaleraPlayers()->whereNull('rating_after')->count());
    }

    public function test_finish_blocked_while_scores_missing(): void
    {
        [$club, $admin, $t] = $this->makeReadyTournament(3);
        app(\App\Services\EscaleraService::class)->startTournament($t);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/finish")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('in_progress', $t->fresh()->status);
    }
}
