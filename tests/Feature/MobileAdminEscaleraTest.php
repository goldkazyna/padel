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

    public function test_create_defaults_standings_mode_to_points(): void
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
        $this->assertSame('points', $t->escalera_standings_mode);
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
}
