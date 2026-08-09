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
}
