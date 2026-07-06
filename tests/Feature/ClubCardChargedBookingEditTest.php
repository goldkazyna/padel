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

class ClubCardChargedBookingEditTest extends TestCase
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

    /** Бронь на 1 час вчера, оплаченная картой. card_charged_at не задаём — списывается через chargeBooking(). */
    private function chargedBooking(Court $court, int $adminId, int $cardId): CourtBooking
    {
        return CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->subDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Иван Иванов',
            'client_phone' => '77770001122',
            'booked_by' => $adminId,
            'price' => 0,
            'status' => 'confirmed',
            'payment_method' => 'club_card',
            'is_paid' => true,
            'club_card_id' => $cardId,
        ]);
    }

    public function test_can_edit_already_charged_card_booking_without_recharge(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '1 ч', 'code_prefix' => 'ONE', 'kind' => 'visits', 'nominal' => 1]);
        $card = (new ClubCardService())->issue($client, $type); // баланс 1

        $booking = $this->chargedBooking($court, $admin->id, $card->id);
        (new ClubCardService())->chargeBooking($booking); // списывает последний час → баланс 0
        $card->refresh();
        $this->assertSame(0, (int) $card->balance);
        $chargedAtBefore = $booking->fresh()->card_charged_at;

        $resp = $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'client_name' => 'Иван Иванов',
            'client_phone' => '77770001122',
            'payment_method' => 'club_card',
            'is_paid' => 1,
            'club_card_id' => $card->id,
            'comment' => 'Обновлённый комментарий',
        ]);

        $resp->assertSessionDoesntHaveErrors();
        $resp->assertSessionHasNoErrors();
        $this->assertNull(session('error'), 'не должно быть ошибки "Выберите действующую клубную карту"');

        $booking->refresh();
        $this->assertSame('Обновлённый комментарий', $booking->comment);
        $this->assertSame($card->id, $booking->club_card_id, 'карта не отвязана');
        $this->assertEquals($chargedAtBefore, $booking->card_charged_at, 'card_charged_at не сбрасывается — без повторного списания');
        $this->assertSame(0, (int) $card->fresh()->balance, 'баланс не меняется');
        $this->assertSame(1, ClubCardTransaction::count(), 'новая транзакция не создаётся');
    }

    public function test_cannot_cancel_charged_card_booking(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '1 ч', 'code_prefix' => 'ONE', 'kind' => 'visits', 'nominal' => 1]);
        $card = (new ClubCardService())->issue($client, $type);

        $booking = $this->chargedBooking($court, $admin->id, $card->id);
        (new ClubCardService())->chargeBooking($booking);

        $resp = $this->actingAs($admin)->post(route('club.courts.cancelBooking', $booking));

        $resp->assertSessionHas('error');
        $this->assertSame('confirmed', $booking->fresh()->status, 'бронь не отменена');
    }

    public function test_forClient_includes_spent_card_when_requested(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '1 ч', 'code_prefix' => 'ONE', 'kind' => 'visits', 'nominal' => 1]);
        $card = (new ClubCardService())->issue($client, $type); // баланс 1
        $booking = $this->chargedBooking($court, $admin->id, $card->id);
        (new ClubCardService())->chargeBooking($booking); // баланс 0 → карта больше не "актуальна"

        $withoutParam = $this->actingAs($admin)->getJson(
            route('club.cards.forClient', ['phone' => '+7 777 000 11 22'])
        );
        $withoutParam->assertOk();
        $this->assertFalse(
            collect($withoutParam->json('cards'))->contains('id', $card->id),
            'без include_card_id потраченная карта не должна попадать в список'
        );

        $withParam = $this->actingAs($admin)->getJson(
            route('club.cards.forClient', ['phone' => '+7 777 000 11 22', 'include_card_id' => $card->id])
        );
        $withParam->assertOk();
        $cards = collect($withParam->json('cards'));
        $found = $cards->firstWhere('id', $card->id);
        $this->assertNotNull($found, 'с include_card_id потраченная карта должна присутствовать');
        $this->assertTrue($found['inactive'], 'потраченная карта помечена inactive => true');
    }
}
