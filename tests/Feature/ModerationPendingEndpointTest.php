<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ModerationPendingEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_nearest_pending_with_deadline(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t1 = Tournament::create([
            'club_id' => $club->id, 'name' => 'Поздний', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        $t2 = Tournament::create([
            'club_id' => $club->id, 'name' => 'Скорый', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        $user = User::factory()->create();
        $t1->participants()->attach($user->id, ['status' => 'pending', 'moderation_deadline' => now()->addHours(10)]);
        $t2->participants()->attach($user->id, ['status' => 'pending', 'moderation_deadline' => now()->addHours(2)]);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/tournaments/moderation-pending')
            ->assertOk()
            ->assertJsonPath('pending.tournament_id', $t2->id)
            ->assertJsonPath('pending.name', 'Скорый');
    }

    public function test_null_when_nothing_pending(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/mobile/tournaments/moderation-pending')
            ->assertOk()
            ->assertJsonPath('pending', null);
    }

    public function test_ignores_registered_and_cancelled(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'X', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        $user = User::factory()->create();
        $t->participants()->attach($user->id, ['status' => 'registered', 'moderation_deadline' => null]);
        Sanctum::actingAs($user);
        $this->getJson('/api/mobile/tournaments/moderation-pending')
            ->assertOk()->assertJsonPath('pending', null);
    }
}
