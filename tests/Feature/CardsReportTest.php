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
use App\Reports\CardsReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardsReportTest extends TestCase
{
    use RefreshDatabase;

    private function club(): Club
    {
        return Club::create(['name' => 'C', 'address' => 'A']);
    }

    public function test_sales_report_lists_cards_bought_in_period(): void
    {
        $club = $this->club();
        $type = ClubCardType::create([
            'club_id' => $club->id, 'name' => 'Абонемент 10', 'code_prefix' => 'AB',
            'kind' => 'visits', 'nominal' => 10, 'price' => 50000, 'is_active' => true,
        ]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван Петров', 'phone' => '+77001112233']);

        // В периоде.
        $inPeriod = ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id,
            'code' => 'AB000001', 'balance' => 10, 'initial_balance' => 10, 'status' => 'active',
        ]);
        $inPeriod->forceFill(['created_at' => '2026-05-10 12:00:00'])->save();

        // Вне периода — не должна попасть.
        $out = ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id,
            'code' => 'AB000002', 'balance' => 10, 'initial_balance' => 10, 'status' => 'active',
        ]);
        $out->forceFill(['created_at' => '2026-04-10 12:00:00'])->save();

        $sheet = app(CardsReportService::class)->sales(
            $club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')
        );

        $this->assertCount(1, $sheet->rows);
        $row = $sheet->rows[0];
        $this->assertSame('Иван Петров', $row[0]);
        $this->assertSame('10.05.2026', $row[5]);
        $this->assertSame(50000, $row[6]);
        $this->assertSame(50000, $sheet->totals[6]); // итог по цене
    }

    public function test_charges_report_lists_hour_deductions_in_period(): void
    {
        $club = $this->club();
        $court = Court::create(['club_id' => $club->id, 'name' => 'K', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60]);
        $type = ClubCardType::create([
            'club_id' => $club->id, 'name' => 'Абонемент 10', 'code_prefix' => 'AB',
            'kind' => 'visits', 'nominal' => 10, 'price' => 50000, 'is_active' => true,
        ]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван Петров', 'phone' => '+77001112233']);
        $card = ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $client->id,
            'code' => 'AB000001', 'balance' => 8, 'initial_balance' => 10, 'status' => 'active',
        ]);

        $booking = CourtBooking::create([
            'court_id' => $court->id, 'date' => '2026-05-15', 'start_time' => '10:00', 'end_time' => '12:00',
            'client_name' => 'Иван Петров', 'client_phone' => '+77001112233', 'status' => 'confirmed',
            'club_card_id' => $card->id, 'booked_by' => User::factory()->create()->id, 'price' => 0,
        ]);

        // Списание 2 часа в периоде.
        ClubCardTransaction::create([
            'club_id' => $club->id, 'club_card_id' => $card->id, 'court_booking_id' => $booking->id,
            'amount' => -2, 'balance_after' => 8, 'note' => 'Списание за бронь (2 ч)',
        ]);
        // Нулевая (пропуск) — не берём.
        ClubCardTransaction::create([
            'club_id' => $club->id, 'club_card_id' => $card->id, 'court_booking_id' => $booking->id,
            'amount' => 0, 'balance_after' => 8, 'note' => 'Не списано (пропущено)',
        ]);

        $sheet = app(CardsReportService::class)->charges(
            $club, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')
        );

        $this->assertCount(1, $sheet->rows);
        $row = $sheet->rows[0];
        $this->assertSame('Иван Петров', $row[0]);
        $this->assertSame('15.05.2026', $row[4]); // дата занятия из брони
        $this->assertSame(2, $row[5]);            // часов
        $this->assertSame(2, $sheet->totals[5]);  // итого часов
    }
}
