<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupSession;
use App\Models\CourtBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CourtBookingCreatesGroupSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_court_booking_with_group_creates_linked_session(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'K1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        ClubClient::create(['club_id' => $club->id, 'name' => 'Иван Иванов', 'phone' => '77770001122']);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'Утро', 'status' => 'active']);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'slots' => 1,
            'client_name' => 'Иван Иванов',
            'client_phone' => '77770001122',
            'payment_method' => 'cash',
            'is_paid' => 1,
            'booking_type' => 'group',
            'group_id' => $group->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertNotNull($booking);
        $this->assertSame('group', $booking->booking_type);

        $session = ClubGroupSession::where('group_id', $group->id)->first();
        $this->assertNotNull($session);
        $this->assertSame($booking->id, $session->court_booking_id);
        $this->assertSame('planned', $session->status);
    }

    public function test_group_booking_price_equals_group_price_per_session(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'K1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'Вечер', 'status' => 'active', 'price_per_session' => 7000]);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '12:00',
            'slots' => 1,
            'booking_type' => 'group',
            'group_id' => $group->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertSame('group', $booking->booking_type);
        $this->assertSame(7000, (int) $booking->price, 'цена группы записалась в бронь');
    }

    public function test_group_booking_without_client_and_payment_fields(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'K1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'Утро', 'status' => 'active']);

        // Только корт/время/группа — без client_name/phone/payment_method/is_paid
        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'slots' => 1,
            'booking_type' => 'group',
            'group_id' => $group->id,
        ])->assertRedirect()->assertSessionHas('success');

        $booking = CourtBooking::first();
        $this->assertNotNull($booking);
        $this->assertSame('Группа: Утро', $booking->client_name);
        $this->assertNull($booking->client_phone);
        $this->assertSame(0.0, (float) $booking->price);
        $this->assertFalse((bool) $booking->is_paid);

        $this->assertSame(1, ClubGroupSession::count());
    }

    public function test_court_booking_without_group_does_not_create_session(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'K1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        ClubClient::create(['club_id' => $club->id, 'name' => 'Иван Иванов', 'phone' => '77770001122']);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'slots' => 1,
            'client_name' => 'Иван Иванов',
            'client_phone' => '77770001122',
            'payment_method' => 'cash',
            'is_paid' => 1,
            'booking_type' => 'group',
            // group_id отсутствует
        ])->assertRedirect();

        $this->assertSame(0, ClubGroupSession::count());
    }
}
