<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Бронь из приложения без телефона клубу бесполезна.
 *
 * Форма подставляет «+» как заготовку, и у входа через Google — где номера
 * в профиле нет — этот плюс уезжал на сервер: в расписании висела бронь,
 * по которой некому позвонить.
 */
class BookingPhoneRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Pulse Padel Club', 'address' => 'А', 'city' => 'Алматы']);
        $this->court = Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 3',
            'open_time' => '07:00', 'close_time' => '23:00',
            'slot_duration' => 60, 'is_active' => true, 'price_per_hour' => 32000,
        ]);
    }

    private function book(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/mobile/courts/clubs/{$this->club->id}/book", array_merge([
            'court_id' => $this->court->id,
            'date' => now('Asia/Almaty')->addDay()->format('Y-m-d'),
            'start_time' => '20:00',
            'slots' => 1,
        ], $body));
    }

    public function test_плюс_вместо_номера_не_проходит(): void
    {
        Sanctum::actingAs(User::factory()->create(['phone' => null, 'name' => 'Ablay Gabdullin']));

        $this->book(['client_phone' => '+'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, CourtBooking::count());
    }

    public function test_телефон_берётся_из_профиля(): void
    {
        Sanctum::actingAs(User::factory()->create(['phone' => '77771112233']));

        $this->book(['client_phone' => '+'])->assertOk();

        $this->assertSame('77771112233', CourtBooking::first()->client_phone);
    }

    public function test_введённый_номер_сохраняется(): void
    {
        Sanctum::actingAs(User::factory()->create(['phone' => '77771112233']));

        $this->book(['client_phone' => '+7 707 000 11 22'])->assertOk();

        $this->assertSame('+7 707 000 11 22', CourtBooking::first()->client_phone);
    }

    public function test_обрывок_номера_тоже_не_проходит(): void
    {
        Sanctum::actingAs(User::factory()->create(['phone' => null]));

        $this->book(['client_phone' => '7777'])->assertStatus(422);
        $this->assertSame(0, CourtBooking::count());
    }
}
