<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileHomeNearestTest extends TestCase
{
    use RefreshDatabase;

    private function club(): Club
    {
        return Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
    }

    public function test_nearest_returns_registered_upcoming_tournament(): void
    {
        $club = $this->club();
        $user = User::factory()->create();

        $registered = Tournament::create([
            'club_id' => $club->id, 'name' => 'Мой турнир', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addDays(2), 'registration_deadline' => now()->addDay(),
        ]);
        $registered->participants()->attach($user->id, ['status' => 'registered']);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('nearest_tournament.id', $registered->id);
    }

    public function test_nearest_null_when_only_not_registered_open_tournaments(): void
    {
        $club = $this->club();
        $user = User::factory()->create();

        // Турнир открыт, но пользователь НЕ записан
        Tournament::create([
            'club_id' => $club->id, 'name' => 'Чужой', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addDays(2), 'registration_deadline' => now()->addDay(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('nearest_tournament', null);
    }

    public function test_nearest_picks_earliest_registered(): void
    {
        $club = $this->club();
        $user = User::factory()->create();

        $later = Tournament::create([
            'club_id' => $club->id, 'name' => 'Поздний', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addDays(5), 'registration_deadline' => now()->addDay(),
        ]);
        $later->participants()->attach($user->id, ['status' => 'registered']);

        $sooner = Tournament::create([
            'club_id' => $club->id, 'name' => 'Ранний', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addDays(1), 'registration_deadline' => now()->addHours(12),
        ]);
        $sooner->participants()->attach($user->id, ['status' => 'registered']);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('nearest_tournament.id', $sooner->id);
    }

    public function test_nearest_excludes_in_progress(): void
    {
        $club = $this->club();
        $user = User::factory()->create();

        $live = Tournament::create([
            'club_id' => $club->id, 'name' => 'Идёт', 'type' => 'americano',
            'status' => 'in_progress', 'max_participants' => 8,
            'start_date' => now()->addDays(1), 'registration_deadline' => now()->subDay(),
        ]);
        $live->participants()->attach($user->id, ['status' => 'registered']);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('nearest_tournament', null);
    }
}
