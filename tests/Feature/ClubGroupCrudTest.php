<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\ClubGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupCrudTest extends TestCase
{
    use RefreshDatabase;

    private function adminClub(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        return [$club, $admin];
    }

    public function test_admin_creates_group(): void
    {
        [$club, $admin] = $this->adminClub();

        $this->actingAs($admin)->post(route('club.groups.store'), [
            'name' => 'Утренняя группа',
            'price_per_session' => 5000,
            'capacity' => 4,
        ])->assertRedirect();

        $g = ClubGroup::where('club_id', $club->id)->first();
        $this->assertNotNull($g);
        $this->assertSame('Утренняя группа', $g->name);
        $this->assertSame(4, (int) $g->capacity);
    }

    public function test_other_club_group_forbidden(): void
    {
        [, $admin] = $this->adminClub();
        $otherClub = Club::create(['name' => 'X', 'address' => 'Y']);
        $foreign = ClubGroup::create(['club_id' => $otherClub->id, 'name' => 'F']);

        $this->actingAs($admin)->get(route('club.groups.show', $foreign))->assertForbidden();
    }

    public function test_destroy_group_cancels_future_bookings(): void
    {
        [$club, $admin] = $this->adminClub();
        $court = \App\Models\Court::create([
            'club_id' => $club->id, 'name' => 'K', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G']);
        $booking = \App\Models\CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00',
            'client_name' => 'Группа: G', 'status' => 'confirmed',
            'booked_by' => $admin->id, 'price' => 0, 'booking_type' => 'group',
        ]);
        \App\Models\ClubGroupSession::create([
            'group_id' => $group->id, 'court_id' => $court->id,
            'court_booking_id' => $booking->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00',
            'status' => 'planned',
        ]);

        $this->actingAs($admin)
            ->delete(route('club.groups.destroy', $group))
            ->assertRedirect();

        $this->assertNull(ClubGroup::find($group->id));
        $this->assertSame('cancelled', $booking->fresh()->status);
    }
}
