<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use App\Models\ClubGroup;
use App\Models\ClubGroupSession;
use App\Models\CourtBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupSessionTest extends TestCase
{
    use RefreshDatabase;

    private function setupCourt(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000]);
        return [$club, $admin, $court, $group];
    }

    public function test_create_session_reserves_court(): void
    {
        [, $admin, $court, $group] = $this->setupCourt();

        $this->actingAs($admin)->post(route('club.groupSessions.store'), [
            'group_id' => $group->id,
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'slots' => 1,
        ])->assertRedirect();

        $session = ClubGroupSession::first();
        $this->assertNotNull($session);
        $this->assertNotNull($session->court_booking_id);
        $booking = CourtBooking::find($session->court_booking_id);
        $this->assertSame('group', $booking->booking_type);
        $this->assertSame('confirmed', $booking->status);
    }

    public function test_journal_matrix_renders(): void
    {
        [, $admin, $court, $group] = $this->setupCourt();
        $date = now()->toDateString();
        ClubGroupSession::create([
            'group_id' => $group->id, 'court_id' => $court->id,
            'date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
            'status' => 'planned',
        ]);

        $this->actingAs($admin)->get(route('club.groupSessions.index'))
            ->assertOk()
            ->assertSee('Журнал занятий')
            ->assertSee('сетка время × дата', false)
            ->assertSee('10:00');
    }

    public function test_conflict_with_existing_booking_blocked(): void
    {
        [, $admin, $court, $group] = $this->setupCourt();
        $date = now()->addDay()->toDateString();
        CourtBooking::create([
            'court_id' => $court->id, 'date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
            'client_name' => 'X', 'client_phone' => '7700', 'status' => 'confirmed',
            'booked_by' => $admin->id, 'price' => 0,
        ]);

        $this->actingAs($admin)->post(route('club.groupSessions.store'), [
            'group_id' => $group->id, 'court_id' => $court->id, 'date' => $date,
            'start_time' => '10:00', 'slots' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(0, ClubGroupSession::count());
    }
}
