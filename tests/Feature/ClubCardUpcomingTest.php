<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubCardUpcomingTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_shows_upcoming_bookings_for_card(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'Корт 1', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $type = ClubCardType::create([
            'club_id' => $club->id, 'name' => 'SMC 15', 'kind' => 'visits', 'nominal' => 15, 'price' => 100000, 'is_active' => true,
        ]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Анжелика', 'phone' => '77017537224']);
        $card = ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id,
            'code' => 'SMC000111', 'balance' => 15, 'initial_balance' => 15, 'status' => 'active',
        ]);

        // Будущая бронь по карте (не списана) — 2 часа.
        CourtBooking::create([
            'court_id' => $court->id, 'date' => now()->addDays(5)->toDateString(),
            'start_time' => '18:00', 'end_time' => '20:00',
            'client_name' => 'Анжелика', 'status' => 'confirmed',
            'club_card_id' => $card->id, 'booked_by' => $admin->id, 'price' => 0,
        ]);
        // Уже списанная бронь — в будущие НЕ попадает.
        CourtBooking::create([
            'court_id' => $court->id, 'date' => now()->subDays(2)->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00',
            'client_name' => 'Анжелика', 'status' => 'confirmed',
            'club_card_id' => $card->id, 'booked_by' => $admin->id, 'price' => 0,
            'card_charged_at' => now()->subDays(2),
        ]);

        $resp = $this->actingAs($admin)->get(route('club.cards.history', $card));
        $resp->assertOk();
        $resp->assertSee('Будущие');
        $resp->assertSee('Корт 1');
        $resp->assertSee('−2 ч'); // запланированное списание 2 часа
        $resp->assertViewHas('upcoming', fn ($u) => $u->count() === 1);
    }
}
