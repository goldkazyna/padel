<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationApiExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_tournaments_expose_deadline(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
            'moderation_hours' => 24,
        ]);
        $user = User::factory()->create(['level' => 3]);
        $t->participants()->attach($user->id, [
            'status' => 'pending', 'moderation_deadline' => now()->addHours(24),
        ]);
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/mobile/tournaments/my')->assertOk();
        $res->assertJsonPath('tournaments.0.moderation_deadline', fn ($v) => $v !== null);
    }
}
