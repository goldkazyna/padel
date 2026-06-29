<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DuplicateTournamentTest extends TestCase
{
    use RefreshDatabase;

    private function makeTournament(Club $club): Tournament
    {
        return Tournament::create([
            'club_id' => $club->id,
            'name' => 'Кубок мая',
            'type' => 'americano',
            'description' => 'Подробное описание турнира',
            'status' => 'open',
            'start_date' => now()->addDays(3),
            'min_level' => 2,
            'max_level' => 4,
            'max_participants' => 12,
            'price' => 5000,
            'courts' => ['Корт 1', 'Корт 2'],
            'courts_count' => 2,
            'moderation_hours' => 48,
            'has_playoff' => true,
            'playoff_type' => 'semifinal_final',
            'verified_only' => true,
            'waitlist_size' => 4,
        ]);
    }

    public function test_admin_duplicates_tournament_as_draft_copy(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $original = $this->makeTournament($club);
        $player = User::factory()->create();
        $original->participants()->attach($player->id, ['status' => 'approved']);

        Sanctum::actingAs($admin);
        $res = $this->postJson("/api/mobile/admin/tournaments/{$original->id}/duplicate");
        $res->assertOk()->assertJsonPath('success', true);

        $this->assertSame(2, Tournament::count());
        $copy = Tournament::where('id', '!=', $original->id)->first();

        // Название с пометкой, статус — черновик, дата очищена.
        $this->assertSame('Кубок мая (копия)', $copy->name);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->start_date);

        // Настройки скопированы один в один.
        $this->assertSame('americano', $copy->type);
        $this->assertSame('Подробное описание турнира', $copy->description);
        $this->assertSame($club->id, $copy->club_id);
        $this->assertSame(12, $copy->max_participants);
        $this->assertSame('5000.00', (string) $copy->price);
        $this->assertSame(['Корт 1', 'Корт 2'], $copy->courts);
        $this->assertSame(48, (int) $copy->moderation_hours);
        $this->assertTrue((bool) $copy->has_playoff);
        $this->assertTrue((bool) $copy->verified_only);

        // Участники НЕ копируются — на дубль записываются заново.
        $this->assertSame(0, $copy->participants()->count());

        // Ответ отдаёт id нового турнира.
        $res->assertJsonPath('tournament.id', $copy->id);
    }

    public function test_non_manager_cannot_duplicate(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $original = $this->makeTournament($club);
        $stranger = User::factory()->create(['role' => 'player']);

        Sanctum::actingAs($stranger);
        $this->postJson("/api/mobile/admin/tournaments/{$original->id}/duplicate")
            ->assertStatus(403);

        $this->assertSame(1, Tournament::count());
    }
}
