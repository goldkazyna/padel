<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationApproveClearsTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_clears_deadline(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 24,
        ]);
        $player = User::factory()->create(['level' => 3]);
        $t->participants()->attach($player->id, [
            'status' => 'pending', 'moderation_deadline' => now()->addHours(24),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/approve")
            ->assertOk();

        $row = $t->participants()->where('user_id', $player->id)->first();
        $this->assertSame('registered', $row->pivot->status);
        $this->assertNull($row->pivot->moderation_deadline);
    }
}
