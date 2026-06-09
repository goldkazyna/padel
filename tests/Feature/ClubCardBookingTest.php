<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Services\ClubCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubCardBookingTest extends TestCase
{
    use RefreshDatabase;

    private function scene(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'K1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван Иванов', 'phone' => '77770001122']);
        return [$club, $admin, $court, $client];
    }

    public function test_discount_card_applies_percent_to_booking(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => 'VIP', 'code_prefix' => 'VIP', 'kind' => 'discount_court', 'discount_percent' => 20]);
        $card = (new ClubCardService())->issue($client, $type);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'slots' => 1,
            'client_name' => 'Иван Иванов',
            'client_phone' => '77770001122',
            'payment_method' => 'club_card',
            'is_paid' => 1,
            'custom_price' => 10000,
            'club_card_id' => $card->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertSame($card->id, $booking->club_card_id);
        $this->assertSame(2000, (int) $booking->discount, 'скидка 20% от 10000');
        $this->assertSame(8000, (int) $booking->price);
    }

    public function test_counter_card_attaches_without_discount(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 пос.', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $card = (new ClubCardService())->issue($client, $type);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '11:00',
            'slots' => 1,
            'client_name' => 'Иван Иванов',
            'client_phone' => '77770001122',
            'payment_method' => 'club_card',
            'is_paid' => 1,
            'custom_price' => 10000,
            'club_card_id' => $card->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertSame($card->id, $booking->club_card_id);
        $this->assertSame(0, (int) $booking->discount, 'счётчик не даёт скидку');
        $this->assertSame(10000, (int) $booking->price);
    }

    public function test_for_client_endpoint_returns_actual_cards(): void
    {
        [$club, $admin, , $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => 'VIP', 'code_prefix' => 'VIP', 'kind' => 'discount_court', 'discount_percent' => 15]);
        (new ClubCardService())->issue($client, $type);

        $resp = $this->actingAs($admin)->getJson(route('club.cards.forClient', ['phone' => '+7 777 000 11 22']));

        $resp->assertOk()->assertJsonPath('cards.0.discount_percent', 15);
        $resp->assertJsonPath('cards.0.is_discount', true);
    }

    public function test_foreign_card_is_ignored_on_booking(): void
    {
        [$club, $admin, $court, $client] = $this->scene();

        // Карта другого клуба — не должна примениться.
        $otherClub = Club::create(['name' => 'D', 'address' => 'B']);
        $otherClient = ClubClient::create(['club_id' => $otherClub->id, 'name' => 'Чужой Клиент', 'phone' => '77009998877']);
        $otherType = ClubCardType::create(['club_id' => $otherClub->id, 'name' => 'X', 'code_prefix' => 'X', 'kind' => 'discount_court', 'discount_percent' => 50]);
        $foreign = (new ClubCardService())->issue($otherClient, $otherType);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '12:00',
            'slots' => 1,
            'client_name' => 'Иван Иванов',
            'client_phone' => '77770001122',
            'payment_method' => 'club_card',
            'is_paid' => 1,
            'custom_price' => 10000,
            'club_card_id' => $foreign->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertNull($booking->club_card_id, 'чужая карта игнорируется');
        $this->assertSame(10000, (int) $booking->price);
    }
}
