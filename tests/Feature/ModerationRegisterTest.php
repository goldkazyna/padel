<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function openTournament(?int $hours = 48): Tournament
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        return Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4, 'waitlist_size' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => $hours,
        ]);
    }

    public function test_register_sets_moderation_deadline(): void
    {
        $t = $this->openTournament(48);
        $user = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/tournaments/{$t->id}/register")->assertOk();

        $row = $t->participants()->where('user_id', $user->id)->first();
        $this->assertSame('pending', $row->pivot->status);
        $this->assertNotNull($row->pivot->moderation_deadline);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id, 'type' => 'tournament_moderation_pending',
        ]);
    }

    public function test_register_without_timer_leaves_deadline_null(): void
    {
        $t = $this->openTournament(null);
        $user = User::factory()->create(['level' => 3]);
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/tournaments/{$t->id}/register")->assertOk();

        $row = $t->participants()->where('user_id', $user->id)->first();
        $this->assertNull($row->pivot->moderation_deadline);
    }

    public function test_can_reregister_after_cancelled(): void
    {
        $t = $this->openTournament(48);
        $user = User::factory()->create(['level' => 3]);
        $t->participants()->attach($user->id, ['status' => 'cancelled']);
        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/tournaments/{$t->id}/register")->assertOk();

        $row = $t->participants()->where('user_id', $user->id)->first();
        $this->assertSame('pending', $row->pivot->status);
        $this->assertNotNull($row->pivot->moderation_deadline);
    }
}
