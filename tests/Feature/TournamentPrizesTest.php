<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TournamentPrizesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);
        return [$club, $admin];
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Кубок',
            'type' => 'americano',
            'max_participants' => 8,
            'min_level' => 1,
            'max_level' => 5,
            'status' => 'open',
            'start_date' => now()->addDays(3)->toIso8601String(),
        ], $override);
    }

    public function test_mobile_store_saves_prizes(): void
    {
        [$club] = $this->admin();

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", $this->payload([
            'has_prizes' => true,
            'prizes' => "1 место — ракетка\n2 место — мяч",
        ]))->assertOk();

        $t = Tournament::first();
        $this->assertTrue((bool) $t->has_prizes);
        $this->assertStringContainsString('ракетка', $t->prizes);
    }

    public function test_mobile_store_nulls_prizes_when_unchecked(): void
    {
        [$club] = $this->admin();

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", $this->payload([
            'has_prizes' => false,
            'prizes' => 'что-то ввели, но галка снята',
        ]))->assertOk();

        $t = Tournament::first();
        $this->assertFalse((bool) $t->has_prizes);
        $this->assertNull($t->prizes);
    }
}
