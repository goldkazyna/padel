<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubGroup;
use App\Models\ClubGroupAttendance;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupSession;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Reports\ClubIncomeReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubIncomeReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_breakdown_sums_group_methods_and_club_card(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'K1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $date = '2026-06-10';
        $uid = \App\Models\User::factory()->create()->id;

        // 1) Группа: проведённое занятие, цена 7000, 2 списанных + 1 не списан → 7000×2 = 14000
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'status' => 'active', 'price_per_session' => 7000]);
        $session = ClubGroupSession::create([
            'group_id' => $group->id, 'court_id' => $court->id, 'date' => $date,
            'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'held',
        ]);
        foreach ([[true], [true], [false]] as $i => [$charged]) {
            $cl = \App\Models\ClubClient::create(['club_id' => $club->id, 'name' => 'M' . $i, 'phone' => '7777000220' . $i]);
            $m = ClubGroupMember::create(['group_id' => $group->id, 'client_id' => $cl->id, 'status' => 'active']);
            ClubGroupAttendance::create([
                'session_id' => $session->id, 'group_member_id' => $m->id,
                'attended' => true, 'charged' => $charged,
            ]);
        }

        // 2) Методы оплаты: наличные оплаченные 12000; карта НЕ оплачена (не считается)
        CourtBooking::create([
            'court_id' => $court->id, 'date' => $date, 'start_time' => '12:00', 'end_time' => '13:00',
            'client_name' => 'A', 'booked_by' => $uid, 'status' => 'confirmed', 'price' => 12000, 'discount' => 0,
            'payment_method' => 'cash', 'is_paid' => true,
        ]);
        CourtBooking::create([
            'court_id' => $court->id, 'date' => $date, 'start_time' => '13:00', 'end_time' => '14:00',
            'client_name' => 'B', 'booked_by' => $uid, 'status' => 'confirmed', 'price' => 9000, 'discount' => 0,
            'payment_method' => 'card', 'is_paid' => false,
        ]);

        // 3) Клубная карта: 15ч/300000 → 20000/ч; бронь 2ч списана → 40000
        $type = ClubCardType::create([
            'club_id' => $club->id, 'name' => 'Карта', 'kind' => 'visits',
            'nominal' => 15, 'price' => 300000, 'is_active' => true,
        ]);
        $cardClient = \App\Models\ClubClient::create(['club_id' => $club->id, 'name' => 'CardOwner', 'phone' => '77770009999']);
        $card = ClubCard::create([
            'club_id' => $club->id, 'club_card_type_id' => $type->id, 'club_client_id' => $cardClient->id,
            'code' => 'X1', 'balance' => 13, 'initial_balance' => 15, 'status' => 'active',
        ]);
        CourtBooking::create([
            'court_id' => $court->id, 'date' => $date, 'start_time' => '15:00', 'end_time' => '17:00',
            'client_name' => 'C', 'booked_by' => $uid, 'status' => 'confirmed', 'price' => 0, 'discount' => 0,
            'club_card_id' => $card->id, 'card_charged_at' => Carbon::parse($date . ' 17:00'),
        ]);

        $sheet = app(ClubIncomeReportService::class)->breakdown(
            $club, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')
        );

        $map = [];
        foreach ($sheet->rows as $r) { $map[$r[0]] = $r[1]; }

        $this->assertSame(14000.0, (float) $map['Групповые']);
        $this->assertSame(12000.0, (float) $map['Наличные']);
        $this->assertSame(0.0, (float) $map['Карта']);
        $this->assertSame(40000.0, (float) $map['Клубная карта']);
        $this->assertSame(66000.0, (float) $sheet->totals[1]);
    }
}
