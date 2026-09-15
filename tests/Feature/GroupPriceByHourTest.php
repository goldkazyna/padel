<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupSession;
use App\Models\Court;
use App\Services\GroupSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Цена группы: за занятие или за час.
 *
 * У группы со смешанным расписанием (час в четверг, два часа в субботу) одна
 * цена «за занятие» означала, что двухчасовое занятие приносит клубу столько
 * же, сколько часовое, — а тренеру за него платится вдвое. Теперь клуб
 * выбирает единицу цены; старые группы остаются «за занятие».
 */
class GroupPriceByHourTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Hills', 'address' => 'А', 'city' => 'Алматы']);
        $this->court = Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 1',
            'price_per_hour' => 10000, 'slot_duration' => 60,
        ]);
    }

    private function group(string $unit, int $members = 5): ClubGroup
    {
        $group = ClubGroup::create([
            'club_id' => $this->club->id,
            'name' => 'Группа 10',
            'price_per_session' => 4000,
            'price_unit' => $unit,
            'capacity' => 8,
            'status' => 'active',
        ]);

        for ($i = 1; $i <= $members; $i++) {
            $client = ClubClient::create([
                'club_id' => $this->club->id, 'name' => "Клиент {$i}", 'phone' => '7700000000' . $i,
            ]);
            ClubGroupMember::create([
                'group_id' => $group->id, 'client_id' => $client->id, 'status' => 'active',
            ]);
        }

        return $group;
    }

    private function lesson(ClubGroup $group, string $start, string $end): ClubGroupSession
    {
        return ClubGroupSession::create([
            'group_id' => $group->id,
            'court_id' => $this->court->id,
            'date' => '2026-09-19',
            'start_time' => $start,
            'end_time' => $end,
            'status' => 'planned',
        ]);
    }

    public function test_по_умолчанию_цена_за_занятие(): void
    {
        $group = ClubGroup::create([
            'club_id' => $this->club->id, 'name' => 'Новая', 'price_per_session' => 4000,
        ]);

        $this->assertSame(ClubGroup::PRICE_UNIT_SESSION, $group->price_unit);
        $this->assertFalse($group->chargesByHour());
        $this->assertSame(4000.0, $group->priceForHours(2));
    }

    public function test_цена_за_час_умножается_на_длительность(): void
    {
        $group = $this->group(ClubGroup::PRICE_UNIT_HOUR);

        $this->assertSame(4000.0, $group->priceForHours(1));
        $this->assertSame(8000.0, $group->priceForHours(2));
        $this->assertSame(6000.0, $group->priceForHours(1.5));
    }

    public function test_выручка_занятия_растёт_с_длительностью(): void
    {
        $service = app(GroupSessionService::class);

        $byHour = $this->group(ClubGroup::PRICE_UNIT_HOUR);
        $this->assertSame(20000.0, $service->sessionRevenue($this->lesson($byHour, '07:00', '08:00')));
        $this->assertSame(40000.0, $service->sessionRevenue($this->lesson($byHour, '13:00', '15:00')));

        // Старая группа считается как раньше — длительность не важна.
        $bySession = $this->group(ClubGroup::PRICE_UNIT_SESSION);
        $this->assertSame(20000.0, $service->sessionRevenue($this->lesson($bySession, '07:00', '08:00')));
        $this->assertSame(20000.0, $service->sessionRevenue($this->lesson($bySession, '13:00', '15:00')));
    }

    public function test_ставка_тренера_за_клиента_считается_той_же_единицей(): void
    {
        $byHour = $this->group(ClubGroup::PRICE_UNIT_HOUR);
        $byHour->update(['coach_price_per_client' => 2700]);

        $this->assertSame(2700.0, $byHour->coachPriceForHours(1));
        $this->assertSame(5400.0, $byHour->coachPriceForHours(2), 'за два часа — вдвое');

        $bySession = $this->group(ClubGroup::PRICE_UNIT_SESSION);
        $bySession->update(['coach_price_per_client' => 2700]);

        $this->assertSame(2700.0, $bySession->coachPriceForHours(2), 'за занятие — сколько задали');
    }

    public function test_без_ставки_за_клиента_платим_по_часовой(): void
    {
        $group = $this->group(ClubGroup::PRICE_UNIT_HOUR);

        $this->assertNull($group->coachPriceForHours(2), 'пусто — считает вызывающий код');
    }

    public function test_занятие_через_полночь_считает_часы_вперёд(): void
    {
        $group = $this->group(ClubGroup::PRICE_UNIT_HOUR);
        $session = $this->lesson($group, '23:00', '00:00');

        $this->assertSame(1.0, $session->hours());
        $this->assertSame(20000.0, app(GroupSessionService::class)->sessionRevenue($session));
    }
}
