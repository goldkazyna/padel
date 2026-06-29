<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UpdateTournamentStatusTest extends TestCase
{
    use RefreshDatabase;

    private function draft(Club $club): Tournament
    {
        return Tournament::create([
            'club_id' => $club->id,
            'name' => 'Дубль кубка',
            'type' => 'americano',
            'status' => 'draft',
            'start_date' => null,
            'min_level' => 1,
            'max_level' => 5,
            'max_participants' => 8,
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Дубль кубка',
            'start_date' => now()->addDays(5)->toIso8601String(),
            'min_level' => 1,
            'max_level' => 5,
            'max_participants' => 8,
            'status' => 'open',
        ], $over);
    }

    public function test_admin_opens_registration_from_draft(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = $this->draft($club);

        Sanctum::actingAs($admin);
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", $this->payload())
            ->assertOk()
            ->assertJsonPath('tournament.status', 'open');

        $this->assertSame('open', $t->fresh()->status);
        $this->assertNotNull($t->fresh()->start_date);
    }

    public function test_status_unchanged_when_not_sent(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = $this->draft($club);

        Sanctum::actingAs($admin);
        // Старое приложение не шлёт status — он не должен меняться.
        $body = $this->payload();
        unset($body['status']);
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", $body)
            ->assertOk();

        $this->assertSame('draft', $t->fresh()->status);
    }

    public function test_cannot_set_invalid_status(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = $this->draft($club);

        Sanctum::actingAs($admin);
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", $this->payload(['status' => 'completed']))
            ->assertStatus(422);

        $this->assertSame('draft', $t->fresh()->status);
    }
}
