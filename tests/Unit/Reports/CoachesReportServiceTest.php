<?php

namespace Tests\Unit\Reports;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\ClubCoach;
use App\Models\CoachRate;
use App\Models\User;
use App\Reports\CoachesReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CoachesReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_uses_coach_rate_then_hourly(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $booker = User::factory()->create();
        $coachUser = User::factory()->create(['first_name' => 'Тренер', 'name' => 'Тренер Один']);
        $cc = ClubCoach::create(['club_id' => $club->id, 'user_id' => $coachUser->id, 'hourly_rate' => 4000]);
        CoachRate::create(['club_coach_id' => $cc->id, 'hours' => 1, 'rate' => 5000]); // 1h => 5000

        // 1h booking with coach => uses coach_rate 5000
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-03', 'start_time' => '08:00', 'end_time' => '09:00', 'client_name' => 'Клиент', 'price' => 7000, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'coach_paid' => true, 'is_paid' => true, 'booked_by' => $booker->id]);

        $svc = new CoachesReportService();
        $salary = $svc->salary($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($salary->rows)->firstWhere(0, 'Тренер Один');
        $this->assertEquals(1, $row[1]);    // sessions
        $this->assertEquals(1.0, $row[2]);  // hours
        $this->assertEquals(5000, $row[3]); // to pay

        $usage = $svc->usage($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $u = collect($usage->rows)->firstWhere(0, 'Тренер Один');
        $this->assertEquals(1, $u[1]);      // sessions
        $this->assertEquals(7000, $u[3]);   // club income

        // Отчёт блочный: имя тренера, заголовок раздела, занятие, итоги.
        $sessions = $svc->sessions($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $first = array_map(fn ($r) => (string) $r[0], $sessions->rows);
        $this->assertSame('Тренер Один', $first[0]);
        $this->assertContains('Итого Тренер Один', $first);
        $this->assertEquals(7000, $sessions->totals[6], 'оплата клиента');
    }

    public function test_income_by_type_splits_by_booking_type(): void
    {
        $club = Club::create(['name' => 'C3', 'address' => 'A3']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K3', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $booker = User::factory()->create();
        $coachUser = User::factory()->create(['name' => 'Тренер Три']);
        $cc = ClubCoach::create(['club_id' => $club->id, 'user_id' => $coachUser->id, 'hourly_rate' => 4000]);

        // индивидуальная с явной coach_price 12000
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-05', 'start_time' => '10:00', 'end_time' => '11:00', 'client_name' => 'К1', 'price' => 0, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'coach_price' => 12000, 'booking_type' => 'individual', 'booked_by' => $booker->id]);
        // групповая без coach_price → ставка×часы = 4000 (2 часа → 8000)
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-06', 'start_time' => '10:00', 'end_time' => '12:00', 'client_name' => 'Группа', 'price' => 0, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'booking_type' => 'group', 'booked_by' => $booker->id]);

        $svc = new CoachesReportService();
        $sheet = $svc->incomeByType($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($sheet->rows)->firstWhere(0, 'Тренер Три');

        // headings: [Тренер, Групповые, Индивидуальные, Мягкая, Турнир, Прочее, Итого]
        $this->assertEquals(8000, $row[1]);   // групповые: ставка 4000 × 2ч
        $this->assertEquals(12000, $row[2]);  // индивидуальные: coach_price
        $this->assertEquals(20000, $row[6]);  // итого
    }

    public function test_unpaid_lists_only_unpaid_coach_bookings(): void
    {
        $club = Club::create(['name' => 'C4', 'address' => 'A4']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'Корт 1', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $booker = User::factory()->create();
        $coachUser = User::factory()->create(['name' => 'Тренер Неоплата']);
        ClubCoach::create(['club_id' => $club->id, 'user_id' => $coachUser->id, 'hourly_rate' => 4000]);

        // A: неоплаченная индивидуальная с явной coach_price 10000 → в отчёте
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-05', 'start_time' => '10:00', 'end_time' => '11:00', 'client_name' => 'Клиент A', 'price' => 0, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'coach_price' => 10000, 'coach_paid' => false, 'booking_type' => 'individual', 'booked_by' => $booker->id]);
        // B: оплаченная → не в отчёте
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-06', 'start_time' => '10:00', 'end_time' => '11:00', 'client_name' => 'Клиент B', 'price' => 0, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'coach_price' => 9000, 'coach_paid' => true, 'booking_type' => 'individual', 'booked_by' => $booker->id]);
        // C: без тренера → не в отчёте
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-07', 'start_time' => '10:00', 'end_time' => '11:00', 'client_name' => 'Клиент C', 'price' => 5000, 'discount' => 0, 'status' => 'confirmed', 'coach_paid' => null, 'booked_by' => $booker->id]);
        // D: неоплаченная вне периода → не в отчёте
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-06-05', 'start_time' => '10:00', 'end_time' => '11:00', 'client_name' => 'Клиент D', 'price' => 0, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'coach_price' => 7000, 'coach_paid' => false, 'booking_type' => 'individual', 'booked_by' => $booker->id]);

        $svc = new CoachesReportService();
        $sheet = $svc->unpaid($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));

        // Только бронь A
        $this->assertCount(1, $sheet->rows);
        $row = $sheet->rows[0];
        // headings: [Дата, Время, Корт, Тренер, Клиент, Тип, Сумма тренеру]
        $this->assertEquals('Тренер Неоплата', $row[3]);
        $this->assertEquals('Клиент A', $row[4]);
        $this->assertEquals(10000, $row[6]);
        $this->assertEquals(10000, $sheet->totals[6]); // итог к выплате
    }

    public function test_unpaid_falls_back_to_hourly_rate_when_no_coach_price(): void
    {
        $club = Club::create(['name' => 'C5', 'address' => 'A5']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'Корт 2', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $booker = User::factory()->create();
        $coachUser = User::factory()->create(['name' => 'Тренер Ставка']);
        ClubCoach::create(['club_id' => $club->id, 'user_id' => $coachUser->id, 'hourly_rate' => 4000]);

        // 2 часа, без coach_price → ставка 4000 × 2ч = 8000
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-10', 'start_time' => '10:00', 'end_time' => '12:00', 'client_name' => 'Клиент', 'price' => 0, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'coach_paid' => false, 'booking_type' => 'individual', 'booked_by' => $booker->id]);

        $svc = new CoachesReportService();
        $sheet = $svc->unpaid($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));

        $this->assertCount(1, $sheet->rows);
        $this->assertEquals(8000, $sheet->rows[0][6]);
    }

    public function test_salary_falls_back_to_hourly_rate_when_no_coach_rates(): void
    {
        $club = Club::create(['name' => 'C2', 'address' => 'A2']);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K2', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $booker = User::factory()->create();
        $coachUser = User::factory()->create(['first_name' => 'Тренер', 'name' => 'Тренер Два']);
        // No CoachRate rows — only hourly_rate
        ClubCoach::create(['club_id' => $club->id, 'user_id' => $coachUser->id, 'hourly_rate' => 4000]);

        // 1h confirmed booking
        CourtBooking::create(['court_id' => $court->id, 'date' => '2026-05-10', 'start_time' => '10:00', 'end_time' => '11:00', 'client_name' => 'Клиент2', 'price' => 6000, 'discount' => 0, 'status' => 'confirmed', 'coach_id' => $coachUser->id, 'coach_paid' => false, 'is_paid' => true, 'booked_by' => $booker->id]);

        $svc = new CoachesReportService();
        $salary = $svc->salary($club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));
        $row = collect($salary->rows)->firstWhere(0, 'Тренер Два');
        $this->assertEquals(1, $row[1]);    // sessions
        $this->assertEquals(1.0, $row[2]);  // hours
        $this->assertEquals(4000, $row[3]); // to_pay = hourly_rate * 1h
    }
}
