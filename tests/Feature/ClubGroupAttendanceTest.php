<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupEnrollment;
use App\Models\ClubGroupSession;
use App\Models\CourtBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(int $sessions = 2): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K1', 'is_active' => true, 'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        $member = ClubGroupMember::create(['group_id' => $group->id, 'client_id' => $client->id]);
        ClubGroupEnrollment::create(['group_member_id' => $member->id, 'sessions' => $sessions, 'amount' => $sessions * 1000]);
        $booking = CourtBooking::create(['court_id' => $court->id, 'date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'client_name' => 'Группа: G', 'status' => 'confirmed', 'booked_by' => $admin->id, 'price' => 0, 'booking_type' => 'group']);
        $session = ClubGroupSession::create(['group_id' => $group->id, 'court_id' => $court->id, 'court_booking_id' => $booking->id, 'date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'planned']);
        return [$club, $admin, $group, $member, $session, $booking];
    }

    public function test_conduct_charges_attendee(): void
    {
        [, $admin, , $member, $session] = $this->scenario(2);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['attended' => 1, 'charged' => 1]],
        ])->assertRedirect();

        $this->assertSame('held', $session->fresh()->status);
        $this->assertSame(1, $member->fresh()->remaining); // 2 - 1
    }

    public function test_conduct_blocked_when_zero_remaining(): void
    {
        [, $admin, , $member, $session] = $this->scenario(0);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['attended' => 1, 'charged' => 1]],
        ])->assertSessionHas('error');

        $this->assertSame('planned', $session->fresh()->status);
    }

    public function test_cancel_frees_court_no_charge(): void
    {
        [, $admin, , $member, $session, $booking] = $this->scenario(2);

        $this->actingAs($admin)->post(route('club.groupSessions.cancel', $session))->assertRedirect();

        $this->assertSame('cancelled', $session->fresh()->status);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(2, $member->fresh()->remaining);
    }

    public function test_reconduct_held_session_blocked(): void
    {
        [, $admin, , $member, $session] = $this->scenario(2);

        // First conduct: attend + charge → remaining 2 -> 1
        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['attended' => 1, 'charged' => 1]],
        ])->assertRedirect();
        $this->assertSame(1, $member->fresh()->remaining);

        // Second conduct attempt on a held session → blocked, balance unchanged
        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['attended' => 1, 'charged' => 1]],
        ])->assertSessionHas('error');
        $this->assertSame(1, $member->fresh()->remaining);
    }
}
