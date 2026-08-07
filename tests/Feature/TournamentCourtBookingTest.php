<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentCourtBookingTest extends TestCase
{
    use RefreshDatabase;

    /** Клуб, админ, корт и турнир с ценой — общая заготовка для тестов. */
    private function setupTournament(float $price = 20000): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $tournament = Tournament::create([
            'club_id' => $club->id,
            'name' => 'Американо',
            'type' => 'americano',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 16,
            'price' => $price,
        ]);

        return [$club, $admin, $court, $tournament];
    }

    public function test_booking_belongs_to_tournament(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Турнир: Американо',
            'status' => 'confirmed',
            'price' => 0,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
            'booked_by' => $admin->id,
        ]);

        $this->assertSame($tournament->id, $booking->fresh()->tournament->id);
        $this->assertTrue($tournament->courtBookings->contains($booking));
    }

    public function test_deleting_tournament_keeps_booking(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Турнир: Американо',
            'status' => 'confirmed',
            'price' => 50000,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
            'booked_by' => $admin->id,
        ]);

        $tournament->delete();

        $booking->refresh();
        $this->assertNull($booking->tournament_id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('50000.00', $booking->price);
    }
}
