<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Court;
use App\Models\Club;
use App\Models\User;
use App\Models\CourtPriceRange;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
use App\Services\CourtScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CourtScheduleTest extends TestCase
{
    use RefreshDatabase;

    private CourtScheduleService $service;
    private Court $court;
    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CourtScheduleService();

        $this->admin = User::factory()->create();
        $this->club = Club::create(['name' => 'Test Club', 'address' => 'Test Address']);
        $this->court = Court::create([
            'club_id' => $this->club->id,
            'name' => 'Корт 1',
            'open_time' => '08:00:00',
            'close_time' => '12:00:00',
            'slot_duration' => 60,
        ]);

        CourtPriceRange::create(['court_id' => $this->court->id, 'time_from' => '08:00', 'time_to' => '10:00', 'price' => 3000]);
        CourtPriceRange::create(['court_id' => $this->court->id, 'time_from' => '10:00', 'time_to' => '12:00', 'price' => 5000]);

        // Refresh to load priceRanges relation
        $this->court->refresh();
    }

    public function test_generates_time_slots(): void
    {
        $slots = $this->service->generateTimeSlots($this->court);
        $this->assertCount(4, $slots);
        $this->assertEquals('08:00', $slots[0]['time']);
        $this->assertEquals('11:00', $slots[3]['time']);
    }

    public function test_slot_prices_from_ranges(): void
    {
        $slots = $this->service->generateTimeSlots($this->court);
        $this->assertEquals(3000, $slots[0]['price']);
        $this->assertEquals(3000, $slots[1]['price']);
        $this->assertEquals(5000, $slots[2]['price']);
        $this->assertEquals(5000, $slots[3]['price']);
    }

    public function test_build_schedule_marks_bookings(): void
    {
        $date = '2026-04-01';
        CourtBooking::create([
            'court_id' => $this->court->id,
            'date' => $date,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'client_name' => 'Тест',
            'status' => 'confirmed',
            'booked_by' => $this->admin->id,
            'price' => 3000,
        ]);

        $schedule = $this->service->buildSchedule($this->court, $date);
        $this->assertEquals('free', $schedule['08:00']['status']);
        $this->assertEquals('booked', $schedule['09:00']['status']);
        $this->assertEquals('Тест', $schedule['09:00']['booking']->client_name);
        $this->assertEquals('free', $schedule['10:00']['status']);
    }

    public function test_build_schedule_marks_blocks(): void
    {
        $date = '2026-04-01';
        CourtBlock::create([
            'court_id' => $this->court->id,
            'date' => $date,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);

        $schedule = $this->service->buildSchedule($this->court, $date);
        $this->assertEquals('blocked', $schedule['08:00']['status']);
        $this->assertEquals('free', $schedule['09:00']['status']);
    }

    public function test_calculate_price_single_hour(): void
    {
        $price = $this->service->calculatePrice($this->court, '08:00', '09:00');
        $this->assertEquals(3000, $price);
    }

    public function test_calculate_price_multi_hour_cross_range(): void
    {
        $price = $this->service->calculatePrice($this->court, '09:00', '11:00');
        $this->assertEquals(8000, $price);
    }

    public function test_max_consecutive_free_slots(): void
    {
        $date = '2026-04-01';
        CourtBooking::create([
            'court_id' => $this->court->id,
            'date' => $date,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'client_name' => 'Тест',
            'status' => 'confirmed',
            'booked_by' => $this->admin->id,
            'price' => 5000,
        ]);

        $max = $this->service->maxConsecutiveFreeSlots($this->court, $date, '08:00');
        $this->assertEquals(2, $max);
    }

    public function test_can_book_returns_true_when_free(): void
    {
        $this->assertTrue($this->service->canBook($this->court, '2026-04-01', '08:00', '10:00'));
    }

    public function test_can_book_returns_false_when_overlap_booking(): void
    {
        CourtBooking::create([
            'court_id' => $this->court->id,
            'date' => '2026-04-01',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'client_name' => 'Тест',
            'status' => 'confirmed',
            'booked_by' => $this->admin->id,
            'price' => 3000,
        ]);

        $this->assertFalse($this->service->canBook($this->court, '2026-04-01', '08:00', '10:00'));
    }

    public function test_can_book_returns_false_when_overlap_block(): void
    {
        CourtBlock::create([
            'court_id' => $this->court->id,
            'date' => '2026-04-01',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->assertFalse($this->service->canBook($this->court, '2026-04-01', '08:00', '10:00'));
    }

    public function test_validate_price_ranges_ok(): void
    {
        $ranges = [
            ['time_from' => '08:00', 'time_to' => '12:00', 'price' => 5000],
        ];
        $errors = $this->service->validatePriceRanges($ranges, '08:00', '12:00');
        $this->assertEmpty($errors);
    }

    public function test_validate_price_ranges_gap(): void
    {
        $ranges = [
            ['time_from' => '08:00', 'time_to' => '10:00', 'price' => 3000],
            ['time_from' => '11:00', 'time_to' => '12:00', 'price' => 5000],
        ];
        $errors = $this->service->validatePriceRanges($ranges, '08:00', '12:00');
        $this->assertNotEmpty($errors);
    }

    public function test_validate_price_ranges_overlap(): void
    {
        $ranges = [
            ['time_from' => '08:00', 'time_to' => '11:00', 'price' => 3000],
            ['time_from' => '10:00', 'time_to' => '12:00', 'price' => 5000],
        ];
        $errors = $this->service->validatePriceRanges($ranges, '08:00', '12:00');
        $this->assertNotEmpty($errors);
    }
}
