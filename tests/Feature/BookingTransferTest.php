<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubGroup;
use App\Models\ClubGroupSession;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Перенос брони: другая дата, другой корт, то же время игры.
 *
 * Раньше бронь приходилось отменять и заводить заново — терялись оплата,
 * комментарий и связь с занятием группы.
 */
class BookingTransferTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court1;
    private Court $court2;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $this->court1 = Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 1',
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
            'price_per_hour' => 10000,
        ]);
        $this->court2 = Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 2',
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
            'price_per_hour' => 12000,
        ]);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function booking(array $attrs = []): CourtBooking
    {
        return CourtBooking::create(array_merge([
            'court_id' => $this->court1->id,
            'date' => '2026-09-20',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'client_name' => 'Иван Петров',
            'client_phone' => '77011112233',
            'price' => 20000,
            'status' => 'confirmed',
            'is_paid' => true,
            'payment_method' => 'kaspi',
            'booked_by' => $this->admin->id,
        ], $attrs));
    }

    public function test_бронь_переезжает_на_другой_корт_и_дату(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->admin)
            ->post("/club/courts/bookings/{$booking->id}/transfer", [
                'date' => '2026-09-21',
                'court_id' => $this->court2->id,
                'start_time' => '14:00',
            ])
            ->assertRedirect();

        $fresh = $booking->fresh();
        $this->assertSame($this->court2->id, $fresh->court_id);
        $this->assertSame('2026-09-21', $fresh->date->format('Y-m-d'));
        $this->assertSame('14:00', substr($fresh->start_time, 0, 5));
        $this->assertSame('16:00', substr($fresh->end_time, 0, 5), 'длительность сохраняется');
        $this->assertEquals(20000, $fresh->price, 'цена не пересчитывается');
        $this->assertTrue((bool) $fresh->is_paid, 'оплата остаётся');
    }

    public function test_на_занятое_время_не_переносим(): void
    {
        $booking = $this->booking();
        $this->booking(['court_id' => $this->court2->id, 'date' => '2026-09-21', 'start_time' => '14:00', 'end_time' => '15:00']);

        $this->actingAs($this->admin)
            ->post("/club/courts/bookings/{$booking->id}/transfer", [
                'date' => '2026-09-21',
                'court_id' => $this->court2->id,
                'start_time' => '14:00',
            ])
            ->assertSessionHas('error');

        $this->assertSame($this->court1->id, $booking->fresh()->court_id, 'бронь осталась на месте');
    }

    public function test_перенос_виден_в_журнале(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->admin)->post("/club/courts/bookings/{$booking->id}/transfer", [
            'date' => '2026-09-21',
            'court_id' => $this->court2->id,
            'start_time' => '14:00',
        ]);

        $log = ActivityLog::where('subject_type', 'CourtBooking')
            ->where('subject_id', $booking->id)
            ->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Перенос брони', $log->description);
        $this->assertStringContainsString('Корт 1, 20.09.2026 10:00', $log->description);
        $this->assertStringContainsString('Корт 2, 21.09.2026 14:00', $log->description);
    }

    public function test_занятие_группы_едет_вместе_с_бронью(): void
    {
        $booking = $this->booking();
        $group = ClubGroup::create([
            'club_id' => $this->club->id, 'name' => 'Группа 1', 'price_per_session' => 4000,
        ]);
        $session = ClubGroupSession::create([
            'group_id' => $group->id,
            'court_id' => $this->court1->id,
            'court_booking_id' => $booking->id,
            'date' => '2026-09-20',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'status' => 'planned',
        ]);

        $this->actingAs($this->admin)->post("/club/courts/bookings/{$booking->id}/transfer", [
            'date' => '2026-09-21',
            'court_id' => $this->court2->id,
            'start_time' => '14:00',
        ]);

        $fresh = $session->fresh();
        $this->assertSame($this->court2->id, $fresh->court_id);
        $this->assertSame('2026-09-21', $fresh->date->format('Y-m-d'));
        $this->assertSame('14:00', substr($fresh->start_time, 0, 5));
    }

    public function test_свободные_слоты_отдаются_с_пометкой_занятости(): void
    {
        $booking = $this->booking();
        // Чужая бронь на втором корте — это время должно прийти занятым.
        $this->booking(['court_id' => $this->court2->id, 'date' => '2026-09-21', 'start_time' => '14:00', 'end_time' => '15:00']);

        $data = $this->actingAs($this->admin)
            ->getJson("/club/courts/bookings/{$booking->id}/transfer-slots?date=2026-09-21")
            ->assertOk()
            ->json();

        $this->assertSame(120, $data['duration']);

        $court2 = collect($data['courts'])->firstWhere('id', $this->court2->id);
        $busy = collect($court2['slots'])->firstWhere('time', '14:00');
        $free = collect($court2['slots'])->firstWhere('time', '16:00');

        $this->assertFalse($busy['free'], '14:00 занято чужой бронью');
        $this->assertTrue($free['free']);
        $this->assertSame('18:00', $free['end'], 'конец считаем по длительности брони');
    }

    public function test_сама_бронь_не_мешает_переносу_на_соседний_час(): void
    {
        // Сдвиг на час вперёд на том же корте: бронь не должна блокировать себя.
        $booking = $this->booking();

        $this->actingAs($this->admin)
            ->post("/club/courts/bookings/{$booking->id}/transfer", [
                'date' => '2026-09-20',
                'court_id' => $this->court1->id,
                'start_time' => '11:00',
            ])
            ->assertSessionHas('success');

        $this->assertSame('11:00', substr($booking->fresh()->start_time, 0, 5));
    }

    public function test_кнопка_переноса_есть_в_обоих_расписаниях(): void
    {
        $this->booking();

        foreach (['/club/courts/schedule', '/club/courts/schedule/week'] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Перенести', $html, "{$url}: нет кнопки");
            $this->assertStringContainsString('id="transferModal"', $html, "{$url}: нет окна переноса");
            $this->assertStringContainsString('bi-arrow-left-right', $html, "{$url}: нет иконки");
        }
    }

    public function test_чужой_клуб_не_переносит(): void
    {
        $booking = $this->booking();
        $stranger = User::factory()->create(['role' => 'club_admin']);
        $other = Club::create(['name' => 'Другой', 'address' => 'Б']);
        $stranger->adminClubs()->attach($other->id);

        $this->actingAs($stranger)
            ->post("/club/courts/bookings/{$booking->id}/transfer", [
                'date' => '2026-09-21',
                'court_id' => $this->court2->id,
                'start_time' => '14:00',
            ])
            ->assertSessionHas('error');

        $this->assertSame('2026-09-20', $booking->fresh()->date->format('Y-m-d'));
    }
}
