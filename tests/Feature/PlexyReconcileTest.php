<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Сверка зависших платежей с Plexy: бронь в pending может быть и брошенной
 * корзиной, и потерянной оплатой. Отличить их можно только спросив шлюз.
 */
class PlexyReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create([
            'name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы',
            'online_payment_enabled' => true,
            'plexy_api_key' => 'test-key',
        ]);
    }

    private function booking(string $paymentId, bool $paid = false): CourtBooking
    {
        $court = Court::create([
            'club_id' => $this->club->id,
            'name' => 'Корт 1',
        ]);
        $user = User::factory()->create();

        return CourtBooking::create([
            'court_id' => $court->id,
            'booked_by' => $user->id,
            'client_name' => 'Игрок',
            'client_phone' => '77770000000',
            'date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '19:00',
            'end_time' => '20:00',
            'price' => 22000,
            'is_paid' => $paid,
            'payment_status' => $paid ? 'paid' : 'pending',
            'payment_id' => $paymentId,
            'payment_method' => 'plexy',
        ]);
    }

    public function test_dry_run_reports_but_does_not_change_anything(): void
    {
        Http::fake([
            '*/v1/payment-links/pl_lost' => Http::response(['id' => 'pl_lost', 'status' => 'paid']),
        ]);

        $booking = $this->booking('pl_lost');

        $this->artisan('payments:reconcile-plexy')
            ->expectsOutputToContain('Потерянных оплат: 1')
            ->assertSuccessful();

        // Без --apply бронь не трогаем: сначала показать, потом решать.
        $this->assertFalse((bool) $booking->fresh()->is_paid);
    }

    public function test_apply_marks_lost_payment_as_paid(): void
    {
        Http::fake([
            '*/v1/payment-links/pl_lost' => Http::response(['id' => 'pl_lost', 'status' => 'charged']),
        ]);

        $booking = $this->booking('pl_lost');

        $this->artisan('payments:reconcile-plexy --apply')->assertSuccessful();

        $fresh = $booking->fresh();
        $this->assertTrue((bool) $fresh->is_paid);
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_abandoned_link_is_left_alone(): void
    {
        // Истёкшая ссылка — человек просто не заплатил, это не потеря.
        Http::fake([
            '*/v1/payment-links/pl_expired' => Http::response(['id' => 'pl_expired', 'status' => 'expired']),
        ]);

        $booking = $this->booking('pl_expired');

        $this->artisan('payments:reconcile-plexy --apply')
            ->expectsOutputToContain('Потерянных оплат: 0')
            ->assertSuccessful();

        $this->assertFalse((bool) $booking->fresh()->is_paid);
    }

    public function test_already_paid_bookings_are_not_queried(): void
    {
        Http::fake();

        $this->booking('pl_done', paid: true);

        $this->artisan('payments:reconcile-plexy')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_gateway_error_does_not_stop_the_run(): void
    {
        // Одна битая ссылка не должна прерывать сверку остальных.
        Http::fake([
            '*/v1/payment-links/pl_broken' => Http::response(['message' => 'not found'], 404),
            '*/v1/payment-links/pl_ok' => Http::response(['id' => 'pl_ok', 'status' => 'paid']),
        ]);

        $broken = $this->booking('pl_broken');
        $ok = $this->booking('pl_ok');

        $this->artisan('payments:reconcile-plexy --apply')->assertSuccessful();

        $this->assertFalse((bool) $broken->fresh()->is_paid);
        $this->assertTrue((bool) $ok->fresh()->is_paid);
    }
}
