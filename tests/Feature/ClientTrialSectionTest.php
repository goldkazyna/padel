<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupAttendance;
use App\Models\ClubGroupSession;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientTrialSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_card_shows_trial_session(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K1', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'Группа 5', 'price_per_session' => 1000]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Тестовый игрок', 'phone' => '77771114447']);
        $session = ClubGroupSession::create([
            'group_id' => $group->id, 'court_id' => $court->id,
            'date' => '2026-06-26', 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'held',
        ]);
        // Пробный гость (по client_id), бесплатно.
        ClubGroupAttendance::create([
            'session_id' => $session->id, 'client_id' => $client->id,
            'attended' => true, 'charged' => false, 'is_trial' => true, 'trial_amount' => 0,
        ]);

        $this->actingAs($admin)
            ->get(route('club.clients.index', ['selected' => $client->id]))
            ->assertOk()
            ->assertSee('Пробные занятия')
            ->assertSee('Группа 5')
            ->assertSee('бесплатно');
    }
}
