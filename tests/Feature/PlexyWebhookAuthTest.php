<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\PaymentLink;
use App\Models\Tournament;
use App\Models\TournamentPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кто имеет право пометить оплату через вебхук Plexy.
 *
 * Эндпоинт открыт наружу, ссылки в нём — «booking-{id}», id идут подряд.
 * Пока пустой секрет означал «принимаем без проверки», клуб без секрета был
 * дырой: чужой POST делал бронь оплаченной. Теперь нет секрета — 401.
 */
class PlexyWebhookAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'super-secret-hook';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'club_admin']);
    }

    private function club(?string $secret = self::SECRET): Club
    {
        return Club::create([
            'name' => 'Клуб',
            'address' => 'А',
            'online_payment_enabled' => true,
            'plexy_api_key' => 'pr_test',
            'plexy_webhook_secret' => $secret,
        ]);
    }

    private function booking(Club $club): CourtBooking
    {
        $court = Court::create([
            'club_id' => $club->id,
            'name' => 'Корт 1',
            'open_time' => '08:00:00',
            'close_time' => '22:00:00',
            'slot_duration' => 60,
        ]);

        return CourtBooking::create([
            'court_id' => $court->id,
            'date' => '2026-09-10',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'client_name' => 'Гость',
            'status' => 'confirmed',
            'price' => 12000,
            'booked_by' => $this->admin->id,
        ]);
    }

    /** @param string|null $secret null — заголовка Authorization нет вовсе */
    private function hook(string $reference, ?string $secret)
    {
        $headers = $secret === null ? [] : ['Authorization' => 'Bearer ' . $secret];

        return $this->withHeaders($headers)->postJson('/api/payment/webhook/plexy', [
            'name' => 'transaction.charged',
            'data' => [
                'id' => 'txn_1',
                'status' => 'charged',
                'merchantReference' => $reference,
            ],
        ]);
    }

    public function test_с_верным_секретом_бронь_становится_оплаченной(): void
    {
        $booking = $this->booking($this->club());

        $this->hook('booking-' . $booking->id, self::SECRET)->assertOk();

        $fresh = $booking->fresh();
        $this->assertTrue((bool) $fresh->is_paid);
        $this->assertSame('paid', $fresh->payment_status);
    }

    public function test_без_заголовка_бронь_не_трогаем(): void
    {
        $booking = $this->booking($this->club());

        $this->hook('booking-' . $booking->id, null)->assertStatus(401);

        $this->assertFalse((bool) $booking->fresh()->is_paid);
    }

    public function test_чужой_секрет_не_проходит(): void
    {
        $booking = $this->booking($this->club());

        $this->hook('booking-' . $booking->id, 'подобранный')->assertStatus(401);

        $this->assertFalse((bool) $booking->fresh()->is_paid);
    }

    public function test_клуб_без_секрета_закрыт_а_не_открыт(): void
    {
        // Главное в этой правке: раньше такой клуб принимал любой POST.
        $booking = $this->booking($this->club(null));

        $this->hook('booking-' . $booking->id, null)->assertStatus(401);
        $this->hook('booking-' . $booking->id, 'что угодно')->assertStatus(401);

        $this->assertFalse((bool) $booking->fresh()->is_paid);
    }

    public function test_оплату_турнира_без_секрета_не_подтвердить(): void
    {
        $club = $this->club(null);
        $club->update(['tournament_payment_enabled' => true]);

        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'price' => 14000,
            'status' => 'open',
            'type' => 'americano',
        ]);

        $payment = TournamentPayment::create([
            'tournament_id' => $tournament->id,
            'user_id' => User::factory()->create()->id,
            'players_count' => 1,
            'amount' => 14000,
            'status' => TournamentPayment::STATUS_PENDING,
            'plexy_link_id' => 'pl_1',
            'expires_at' => now()->addMinutes(20),
        ]);

        $this->hook('tourpay-' . $payment->id, 'что угодно')->assertStatus(401);

        $this->assertSame(TournamentPayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_счёт_клиенту_без_секрета_не_оплатить(): void
    {
        $club = $this->club(null);

        $link = PaymentLink::create([
            'club_id' => $club->id,
            'created_by' => $this->admin->id,
            'amount' => 22000,
            'description' => 'Карта',
            'status' => PaymentLink::STATUS_PENDING,
            'plexy_link_id' => 'pl_2',
            'expires_at' => now()->addDay(),
        ]);

        $this->hook('paylink-' . $link->id, 'что угодно')->assertStatus(401);

        $this->assertFalse($link->fresh()->isPaid());
    }
}
