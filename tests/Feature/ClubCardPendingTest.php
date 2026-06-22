<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardTransaction;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Services\ClubCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubCardPendingTest extends TestCase
{
    use RefreshDatabase;

    private function scene(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K1', 'is_active' => true, 'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван', 'phone' => '77770001122']);
        $type = ClubCardType::create(['club_id' => $club->id, 'name' => '10 ч', 'code_prefix' => 'VIS', 'kind' => 'visits', 'nominal' => 10]);
        $card = (new ClubCardService())->issue($client, $type);
        return [$club, $admin, $court, $card];
    }

    private function endedBooking(Court $court, int $cardId, ?int $bookedBy = null): CourtBooking
    {
        if ($bookedBy === null) {
            $bookedBy = User::factory()->create()->id;
        }
        return CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->subDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '12:00',
            'client_name' => 'Иван', 'client_phone' => '77770001122',
            'booked_by' => $bookedBy, 'price' => 0, 'status' => 'confirmed',
            'club_card_id' => $cardId,
        ]);
    }

    public function test_journal_page_renders(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        $b = $this->endedBooking($court, $card->id);
        (new \App\Services\ClubCardService())->chargeBooking($b); // запись в журнал

        $this->actingAs($admin)->get(route('club.cards.journal'))
            ->assertOk()
            ->assertSee('Журнал клубных карт');
    }

    public function test_unlinked_page_lists_bookings_without_card(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        // Бронь с оплатой клубной картой, но без привязанной карты, после 15.06.
        \App\Models\CourtBooking::create([
            'court_id' => $court->id, 'date' => '2026-06-18',
            'start_time' => '10:00', 'end_time' => '11:00',
            'client_name' => 'Безкарты Клиент', 'client_phone' => '77770009988',
            'booked_by' => $admin->id, 'price' => 5000, 'status' => 'confirmed',
            'payment_method' => 'club_card', 'club_card_id' => null,
        ]);

        $this->actingAs($admin)->get(route('club.cards.unlinked'))
            ->assertOk()
            ->assertSee('Не выставлены карты')
            ->assertSee('Безкарты Клиент');
    }

    public function test_charge_action_deducts_hours(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        $b = $this->endedBooking($court, $card->id);

        $this->actingAs($admin)
            ->post(route('club.cards.pending.charge', $b))
            ->assertRedirect();

        $this->assertSame(8, (int) $card->fresh()->balance);
        $this->assertNotNull($b->fresh()->card_charged_at);
    }

    public function test_skip_action_marks_without_deduction(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        $b = $this->endedBooking($court, $card->id);

        $this->actingAs($admin)
            ->post(route('club.cards.pending.skip', $b))
            ->assertRedirect();

        $this->assertSame(10, (int) $card->fresh()->balance);
        $this->assertNotNull($b->fresh()->card_charged_at);
        $this->assertSame(1, ClubCardTransaction::where('amount', 0)->count());
    }

    public function test_other_club_booking_forbidden(): void
    {
        [$club, $admin, $court, $card] = $this->scene();

        $otherClub = Club::create(['name' => 'X', 'address' => 'Y']);
        $otherCourt = Court::create(['club_id' => $otherClub->id, 'name' => 'KX', 'is_active' => true, 'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60]);
        $foreign = $this->endedBooking($otherCourt, $card->id);

        $this->actingAs($admin)
            ->post(route('club.cards.pending.charge', $foreign))
            ->assertForbidden();
    }

    public function test_index_shows_pending_badge_count(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        $this->endedBooking($court, $card->id);
        $this->endedBooking($court, $card->id);

        $this->actingAs($admin)->get(route('club.cards.index'))
            ->assertOk()
            ->assertSee('К списанию')
            ->assertSee('2'); // бейдж = 2 брони
    }

    public function test_pending_page_lists_booking(): void
    {
        [$club, $admin, $court, $card] = $this->scene();
        $this->endedBooking($court, $card->id);

        $this->actingAs($admin)->get(route('club.cards.pending'))
            ->assertOk()
            ->assertSee('Иван');
    }
}
