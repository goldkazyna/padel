<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileClubShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_cover_community_and_open_count(): void
    {
        $club = Club::create([
            'name' => 'C', 'address' => 'A', 'city' => 'Алматы',
            'is_community' => true, 'cover' => '/covers/c.jpg',
        ]);

        Tournament::create([
            'club_id' => $club->id, 'name' => 'Open', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        Tournament::create([
            'club_id' => $club->id, 'name' => 'Done', 'type' => 'americano',
            'status' => 'completed', 'max_participants' => 8,
            'start_date' => now()->subDays(3), 'registration_deadline' => now()->subDays(4),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/clubs/{$club->id}")
            ->assertOk()
            ->assertJsonPath('club.is_community', true)
            ->assertJsonPath('club.open_tournaments_count', 1)
            ->assertJsonPath('club.cover', url('/covers/c.jpg'));
    }

    public function test_show_cover_null_when_absent(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/mobile/clubs/{$club->id}")
            ->assertOk()
            ->assertJsonPath('club.cover', null)
            ->assertJsonPath('club.open_tournaments_count', 0);
    }
}
