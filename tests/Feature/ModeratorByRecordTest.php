<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Модератором делает запись в club_moderators, а не строка роли.
 *
 * Тренера клуба назначили модератором с полным доступом к турнирам — и он
 * упирался в «Нет прав на создание турниров»: проверка смотрела на
 * `role === 'club_moderator'`, а роль у него осталась тренерской.
 */
class ModeratorByRecordTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create([
            'name' => 'Ace Padel Club',
            'address' => 'А',
            'city' => 'Алматы',
        ]);
    }

    private function moderator(string $role, bool $fullAccess = true): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->moderatorClubs()->attach($this->club->id, [
            'tournaments_full_access' => $fullAccess,
            'can_view_activity_log' => true,
        ]);

        return $user->fresh();
    }

    public function test_тренер_модератор_считается_модератором(): void
    {
        $coach = $this->moderator('coach');

        $this->assertTrue($coach->isClubModerator());
        $this->assertTrue($coach->hasTournamentsFullAccess($this->club));
    }

    public function test_тренер_модератор_создаёт_турнир_в_приложении(): void
    {
        $coach = $this->moderator('coach');
        Sanctum::actingAs($coach);

        $this->postJson("/api/mobile/admin/clubs/{$this->club->id}/tournaments", [
            'name' => 'Вечерний Американо',
            'type' => 'americano',
            'start_date' => now('Asia/Almaty')->addDay()->format('Y-m-d H:i:s'),
            'min_level' => 1.0,
            'max_level' => 5.0,
            'max_participants' => 8,
            'status' => 'open',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_модератор_без_галочки_турниры_не_создаёт(): void
    {
        $coach = $this->moderator('coach', fullAccess: false);
        Sanctum::actingAs($coach);

        $this->postJson("/api/mobile/admin/clubs/{$this->club->id}/tournaments", [
            'name' => 'Вечерний Американо',
            'type' => 'americano',
            'start_date' => now('Asia/Almaty')->addDay()->format('Y-m-d H:i:s'),
            'min_level' => 1.0,
            'max_level' => 5.0,
            'max_participants' => 8,
            'status' => 'open',
        ])->assertStatus(403);
    }

    public function test_тренер_модератор_попадает_в_кабинет_клуба(): void
    {
        $coach = $this->moderator('coach');

        // Дверь в /club/* проверяла строку роли и отдавала 403.
        $this->actingAs($coach)->get('/club/tournaments')->assertOk();
    }

    public function test_посторонний_тренер_в_кабинет_не_попадает(): void
    {
        $stranger = User::factory()->create(['role' => 'coach']);

        $this->actingAs($stranger)->get('/club/tournaments')->assertForbidden();
    }

    public function test_владелец_клуба_остаётся_админом(): void
    {
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($this->club->id);
        $admin->moderatorClubs()->attach($this->club->id, [
            'tournaments_full_access' => false,
            'can_view_activity_log' => false,
        ]);

        $admin = $admin->fresh();

        // Случайная запись модератора не должна урезать права владельца.
        $this->assertFalse($admin->isClubModerator());
        $this->assertTrue($admin->hasTournamentsFullAccess($this->club));
    }
}
