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

    public function test_groups_index_renders(): void
    {
        [$club, $admin] = $this->adminClub();
        $coach = User::factory()->create(['role' => 'club_coach', 'first_name' => 'Иван', 'last_name' => 'Петров']);
        ClubGroup::create([
            'club_id' => $club->id, 'name' => 'Утренняя', 'coach_id' => $coach->id,
            'price_per_session' => 5000, 'capacity' => 4, 'status' => 'active',
        ]);

        $this->actingAs($admin)->get(route('club.groups.index'))
            ->assertOk()
            ->assertSee('Утренняя')
            ->assertSee('Тренер');
    }

    public function test_other_club_group_forbidden(): void
    {
        [, $admin] = $this->adminClub();
        $otherClub = Club::create(['name' => 'X', 'address' => 'Y']);
        $foreign = ClubGroup::create(['club_id' => $otherClub->id, 'name' => 'F']);

        $this->actingAs($admin)->get(route('club.groups.show', $foreign))->assertForbidden();
    }

    /**
     * Удаление группы закрыто: оно уносило всю историю — занятия,
     * посещаемость, списания по абонементам. Вместо него архив.
     */
    public function test_группу_нельзя_удалить_даже_прямым_запросом(): void
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
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotNull(ClubGroup::find($group->id), 'группа должна остаться');
        $this->assertSame('confirmed', $booking->fresh()->status,
            'бронь трогать не должны — занятия отменяет архив, а не удаление');
    }

    public function test_archive_group_cancels_future_bookings_and_sets_status(): void
    {
        [$club, $admin] = $this->adminClub();
        $court = \App\Models\Court::create([
            'club_id' => $club->id, 'name' => 'K', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'status' => 'active']);
        $booking = \App\Models\CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00',
            'client_name' => 'Группа: G', 'status' => 'confirmed',
            'booked_by' => $admin->id, 'price' => 0, 'booking_type' => 'group',
        ]);
        $session = \App\Models\ClubGroupSession::create([
            'group_id' => $group->id, 'court_id' => $court->id,
            'court_booking_id' => $booking->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00',
            'status' => 'planned',
        ]);

        $this->actingAs($admin)
            ->post(route('club.groups.archive', $group))
            ->assertRedirect();

        $this->assertSame('archived', $group->fresh()->status);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('cancelled', $session->fresh()->status);
    }

    public function test_unarchive_group_sets_active(): void
    {
        [$club, $admin] = $this->adminClub();
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'status' => 'archived']);

        $this->actingAs($admin)
            ->post(route('club.groups.unarchive', $group))
            ->assertRedirect();

        $this->assertSame('active', $group->fresh()->status);
    }
}
