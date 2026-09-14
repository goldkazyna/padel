<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Номер транзакции у брони.
 *
 * Клубам, которые сверяют выручку с выпиской, нужен номер платежа рядом
 * с бронью. Включается галочкой в настройках и спрашивается только
 * у оплаченных: у наличных и неоплаченных номера просто нет.
 */
class BookingTransactionNumberTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
        $this->court = Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 1',
            'open_time' => '07:00', 'close_time' => '23:00',
            'slot_duration' => 60, 'is_active' => true, 'price_per_hour' => 20000,
        ]);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function book(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post("/club/courts/{$this->court->id}/book", array_merge([
            'date' => now('Asia/Almaty')->addDay()->format('Y-m-d'),
            'start_time' => '19:00',
            'slots' => 1,
            'client_name' => 'Иван Петров',
            'client_phone' => '77771112233',
            'payment_method' => 'kaspi',
            'is_paid' => 1,
        ], $extra));
    }

    public function test_без_галочки_номер_не_спрашивают(): void
    {
        $this->book()->assertRedirect();

        $this->assertSame(1, CourtBooking::count());
        $this->assertNull(CourtBooking::first()->transaction_number);
    }

    public function test_с_галочкой_оплаченная_бронь_требует_номер(): void
    {
        $this->club->update(['require_transaction_number' => true]);

        $this->book()->assertSessionHasErrors('transaction_number');
        $this->assertSame(0, CourtBooking::count());
    }

    public function test_номер_сохраняется(): void
    {
        $this->club->update(['require_transaction_number' => true]);

        $this->book(['transaction_number' => '625421045265'])->assertRedirect();

        $this->assertSame('625421045265', CourtBooking::first()->transaction_number);
    }

    public function test_неоплаченную_бронь_пускают_без_номера(): void
    {
        $this->club->update(['require_transaction_number' => true]);

        $this->book(['is_paid' => 0])->assertRedirect();

        $this->assertSame(1, CourtBooking::count());
    }

    public function test_при_редактировании_правило_то_же(): void
    {
        $this->club->update(['require_transaction_number' => true]);
        $this->book(['is_paid' => 0])->assertRedirect();
        $booking = CourtBooking::first();

        $payload = [
            'court_id' => $this->court->id,
            'date' => $booking->date instanceof \Carbon\Carbon
                ? $booking->date->format('Y-m-d')
                : (string) $booking->date,
            'start_time' => '19:00',
            'slots' => 1,
            'client_name' => 'Иван Петров',
            'client_phone' => '77771112233',
            'payment_method' => 'kaspi',
            'is_paid' => 1,
        ];

        // Отметили оплату — номер обязателен.
        $this->actingAs($this->admin)
            ->put("/club/courts/bookings/{$booking->id}", $payload)
            ->assertSessionHasErrors('transaction_number');

        $this->actingAs($this->admin)
            ->put("/club/courts/bookings/{$booking->id}",
                $payload + ['transaction_number' => 'A-77'])
            ->assertRedirect();

        $this->assertSame('A-77', $booking->fresh()->transaction_number);
    }

    public function test_галочка_сохраняется_из_настроек(): void
    {
        $this->actingAs($this->admin)->put('/club/settings/club', [
            'require_transaction_number' => '1',
            'booking_cancel_hours' => 2,
        ])->assertRedirect();

        $this->assertTrue((bool) $this->club->fresh()->require_transaction_number);
    }

    public function test_поле_видно_в_обоих_расписаниях(): void
    {
        $this->club->update(['require_transaction_number' => true]);

        foreach (['/club/courts/schedule', '/club/courts/schedule/week'] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('name="transaction_number"', $html, $url);
            $this->assertStringContainsString('Номер транзакции', $html, $url);
        }
    }

    public function test_без_галочки_поля_в_расписании_нет(): void
    {
        $html = $this->actingAs($this->admin)->get('/club/courts/schedule')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="transaction_number"', $html);
    }
}
