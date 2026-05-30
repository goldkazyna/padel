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

    public function test_show_returns_only_coaches_with_photo(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        $withPhoto = User::factory()->create(['first_name' => 'Иван', 'last_name' => 'Петров']);
        $noPhoto = User::factory()->create(['first_name' => 'Без', 'last_name' => 'Фото']);

        \App\Models\ClubCoach::create([
            'club_id' => $club->id, 'user_id' => $withPhoto->id,
            'specialization' => 'Дети', 'photo' => '/coaches/ivan.jpg?v=1',
        ]);
        \App\Models\ClubCoach::create([
            'club_id' => $club->id, 'user_id' => $noPhoto->id,
            'specialization' => 'Взрослые', 'photo' => null,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $res = $this->getJson("/api/mobile/clubs/{$club->id}")->assertOk();
        $res->assertJsonCount(1, 'club.coaches')
            ->assertJsonPath('club.coaches.0.specialization', 'Дети')
            ->assertJsonPath('club.coaches.0.photo', url('/coaches/ivan.jpg?v=1'));
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
