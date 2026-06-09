<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCardTransaction;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Services\ClubCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubCardChargeTest extends TestCase
{
    use RefreshDatabase;

    private function scene(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'K1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван', 'phone' => '77770001122']);
        $user = User::factory()->create(['role' => 'club_admin']);
        $this->bookedBy = $user->id;
        return [$club, $court, $client, $user];
    }

    private function booking(Court $court, $cardId, string $date, string $start, string $end, string $status = 'confirmed'): CourtBooking
    {
        return CourtBooking::create([
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'client_name' => 'Иван',
            'client_phone' => '77770001122',
            'booked_by' => $this->bookedBy,
            'price' => 0,
            'status' => $status,
            'club_card_id' => $cardId,
        ]);
    }

    private int $bookedBy = 0;

    public function test_charge_deducts_hours_and_logs(): void
    {
        [$club, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 ч', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $card = (new ClubCardService())->issue($client, $type);
        // Бронь на 2 часа, вчера (завершена).
        $b = $this->booking($court, $card->id, now()->subDay()->toDateString(), '10:00', '12:00');

        $tx = (new ClubCardService())->chargeBooking($b);

        $this->assertNotNull($tx);
        $this->assertSame(-2, $tx->amount);
        $this->assertSame(8, $tx->balance_after);
        $this->assertSame(8, (int) $card->fresh()->balance);
        $this->assertNotNull($b->fresh()->card_charged_at);
    }

    public function test_charge_is_idempotent(): void
    {
        [$club, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 ч', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $card = (new ClubCardService())->issue($client, $type);
        $b = $this->booking($court, $card->id, now()->subDay()->toDateString(), '10:00', '11:00');

        (new ClubCardService())->chargeBooking($b);
        (new ClubCardService())->chargeBooking($b->fresh());

        $this->assertSame(9, (int) $card->fresh()->balance, 'повторный вызов не списывает дважды');
        $this->assertSame(1, ClubCardTransaction::count());
    }

    public function test_cancelled_booking_not_charged(): void
    {
        [$club, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 ч', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $card = (new ClubCardService())->issue($client, $type);
        $b = $this->booking($court, $card->id, now()->subDay()->toDateString(), '10:00', '11:00', 'cancelled');

        $tx = (new ClubCardService())->chargeBooking($b);

        $this->assertNull($tx);
        $this->assertSame(10, (int) $card->fresh()->balance);
        $this->assertSame(0, ClubCardTransaction::count());
    }

    public function test_discount_card_marks_charged_without_deduction(): void
    {
        [$club, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => 'VIP', 'code_prefix' => 'VIP', 'kind' => 'discount_court', 'discount_percent' => 20]);
        $card = (new ClubCardService())->issue($client, $type);
        $b = $this->booking($court, $card->id, now()->subDay()->toDateString(), '10:00', '11:00');

        $tx = (new ClubCardService())->chargeBooking($b);

        $this->assertNull($tx);
        $this->assertSame(0, ClubCardTransaction::count());
        $this->assertNotNull($b->fresh()->card_charged_at, 'помечена обработанной');
    }

    public function test_balance_does_not_go_negative(): void
    {
        [$club, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '1 ч', 'code_prefix' => 'ONE', 'kind' => 'visits', 'nominal' => 1]);
        $card = (new ClubCardService())->issue($client, $type); // баланс 1
        $b = $this->booking($court, $card->id, now()->subDay()->toDateString(), '10:00', '13:00'); // 3 часа

        $tx = (new ClubCardService())->chargeBooking($b);

        $this->assertSame(-1, $tx->amount, 'списали только доступный 1 ч');
        $this->assertSame(0, (int) $card->fresh()->balance);
    }

    public function test_command_charges_only_ended_bookings(): void
    {
        [$club, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 ч', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $card = (new ClubCardService())->issue($client, $type);

        $past = $this->booking($court, $card->id, now()->subDay()->toDateString(), '10:00', '11:00');
        $future = $this->booking($court, $card->id, now()->addDay()->toDateString(), '10:00', '11:00');

        $this->artisan('cards:charge-due')->assertSuccessful();

        $this->assertNotNull($past->fresh()->card_charged_at, 'прошедшая списана');
        $this->assertNull($future->fresh()->card_charged_at, 'будущая не тронута');
        $this->assertSame(9, (int) $card->fresh()->balance);
    }
}
