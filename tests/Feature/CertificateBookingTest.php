<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateBookingTest extends TestCase
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

    private function cert(Club $club, ClubClient $client, array $attrs): Certificate
    {
        return Certificate::create(array_merge([
            'club_id' => $club->id,
            'client_id' => $client->id,
            'type' => 'named',
            'recipient_name' => $client->name,
            'number' => Certificate::generateNumber($club->id),
        ], $attrs));
    }

    public function test_amount_certificate_applies_as_discount_and_redeems(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $cert = $this->cert($club, $client, ['value_type' => 'amount', 'amount' => 3000]);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'slots' => 1,
            'client_name' => 'Иван Иванов', 'client_phone' => '77770001122',
            'payment_method' => 'certificate', 'is_paid' => 1,
            'custom_price' => 10000, 'certificate_id' => $cert->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertSame($cert->id, $booking->certificate_id);
        $this->assertSame(3000, (int) $booking->discount);
        $this->assertSame(7000, (int) $booking->price);
        $this->assertNotNull($cert->fresh()->used_at, 'сертификат погашен после брони');
    }

    public function test_amount_certificate_capped_at_price(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $cert = $this->cert($club, $client, ['value_type' => 'amount', 'amount' => 50000]);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '11:00', 'slots' => 1,
            'client_name' => 'Иван Иванов', 'client_phone' => '77770001122',
            'payment_method' => 'certificate', 'is_paid' => 1,
            'custom_price' => 10000, 'certificate_id' => $cert->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertSame(10000, (int) $booking->discount, 'скидка не больше цены');
        $this->assertSame(0, (int) $booking->price);
    }

    public function test_free_certificate_makes_total_zero(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $cert = $this->cert($club, $client, ['value_type' => 'hours', 'hours' => 1]);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '12:00', 'slots' => 1,
            'client_name' => 'Иван Иванов', 'client_phone' => '77770001122',
            'payment_method' => 'certificate', 'is_paid' => 1,
            'custom_price' => 10000, 'certificate_id' => $cert->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertSame(10000, (int) $booking->discount);
        $this->assertSame(0, (int) $booking->price, 'бесплатный сертификат → итог 0');
        $this->assertNotNull($cert->fresh()->used_at);
    }

    public function test_hours_certificate_covers_only_its_hours(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $cert = $this->cert($club, $client, ['value_type' => 'hours', 'hours' => 2]);

        // Бронь 3 часа, цена 30000 (10000/ч). Сертификат на 2 ч → покрывает 2 ч,
        // остаётся 1 час платно.
        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '09:00', 'slots' => 3,
            'client_name' => 'Иван Иванов', 'client_phone' => '77770001122',
            'payment_method' => 'certificate', 'is_paid' => 1,
            'custom_price' => 30000, 'certificate_id' => $cert->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertSame(20000, (int) $booking->discount, 'сертификат покрывает 2 из 3 часов');
        $this->assertSame(10000, (int) $booking->price, 'остаётся 1 час платно');
        $this->assertNotNull($cert->fresh()->used_at);
    }

    public function test_cancel_cert_booking_requires_reason_and_returns_certificate(): void
    {
        [$club, $admin, $court, $client] = $this->scene();
        $cert = $this->cert($club, $client, ['value_type' => 'amount', 'amount' => 3000]);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'slots' => 1,
            'client_name' => 'Иван Иванов', 'client_phone' => '77770001122',
            'payment_method' => 'certificate', 'is_paid' => 1,
            'custom_price' => 10000, 'certificate_id' => $cert->id,
        ])->assertRedirect();

        $booking = CourtBooking::first();
        $this->assertNotNull($cert->fresh()->used_at, 'после брони погашен');

        // Отмена без причины — заблокирована.
        $this->actingAs($admin)->post(route('club.courts.cancelBooking', $booking), [])
            ->assertSessionHas('error');
        $this->assertNotNull($cert->fresh()->used_at, 'без причины не отменилось, сертификат ещё погашен');
        $this->assertNotSame('cancelled', $booking->fresh()->status);

        // Отмена с причиной — ок, сертификат возвращается в активные.
        $this->actingAs($admin)->post(route('club.courts.cancelBooking', $booking), ['reason' => 'клиент передумал'])
            ->assertRedirect();
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertNull($cert->fresh()->used_at, 'после отмены снова активен');
        $this->assertSame('клиент передумал', $booking->fresh()->cancel_reason);
    }

    public function test_any_booking_cancel_requires_reason(): void
    {
        [$club, $admin, $court, $client] = $this->scene();

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '14:00', 'slots' => 1,
            'client_name' => 'Иван Иванов', 'client_phone' => '77770001122',
            'payment_method' => 'cash', 'is_paid' => 1, 'custom_price' => 10000,
        ])->assertRedirect();
        $booking = CourtBooking::first();

        // Без причины — отмена заблокирована.
        $this->actingAs($admin)->post(route('club.courts.cancelBooking', $booking))
            ->assertSessionHas('error');
        $this->assertNotSame('cancelled', $booking->fresh()->status);

        // С причиной — отменяется, причина сохраняется.
        $this->actingAs($admin)->post(route('club.courts.cancelBooking', $booking), ['reason' => 'клиент не пришёл'])
            ->assertRedirect();
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('клиент не пришёл', $booking->fresh()->cancel_reason);
    }

    public function test_for_client_endpoint_returns_only_active_certs(): void
    {
        [$club, $admin, , $client] = $this->scene();
        $this->cert($club, $client, ['value_type' => 'amount', 'amount' => 5000]);
        $this->cert($club, $client, ['value_type' => 'hours', 'hours' => 2, 'used_at' => now()]); // погашен → не в списке

        $resp = $this->actingAs($admin)->getJson(route('club.certificates.forClient', ['phone' => '+7 777 000 11 22']));

        $resp->assertOk()->assertJsonCount(1, 'certificates');
        $resp->assertJsonPath('certificates.0.amount', 5000);
        $resp->assertJsonPath('certificates.0.is_free', false);
    }
}
