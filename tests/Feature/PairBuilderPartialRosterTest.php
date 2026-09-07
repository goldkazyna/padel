<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use App\Services\TeamTournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сбор пар при неполном составе.
 *
 * Раньше сборщик открывался только когда подтверждены все места: одна заявка
 * на модерации — и организатор не мог тронуть пары до последнего момента.
 * Теперь пары собираются из подтверждённых, а опоздавших доставляют потом.
 */
class PairBuilderPartialRosterTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Tournament $tournament;

    /** @var array<int, User> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
        $this->tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => 'americano_flex',
            'status' => 'open',
            'is_paired' => true,
            'pairing_mode' => 'admin',
            'max_participants' => 8,
        ]);

        // Пять подтверждённых и один на модерации: состав неполный.
        for ($i = 0; $i < 5; $i++) {
            $u = User::factory()->create(['rating' => 2000 + $i * 50]);
            $this->tournament->participants()->attach($u->id, ['status' => 'registered']);
            $this->players[] = $u;
        }
        $pending = User::factory()->create();
        $this->tournament->participants()->attach($pending->id, ['status' => 'pending']);
    }

    public function test_пары_сохраняются_при_неполном_составе(): void
    {
        $service = app(TeamTournamentService::class);

        [$ok, $msg] = $service->savePairs($this->tournament, [
            [$this->players[0]->id, $this->players[1]->id],
            [$this->players[2]->id, $this->players[3]->id],
        ]);

        $this->assertTrue($ok, $msg);
        $this->assertSame(2, $this->tournament->teams()->count());
    }

    public function test_пара_с_тем_кто_на_модерации_не_проходит(): void
    {
        $pendingId = $this->tournament->participants()
            ->wherePivot('status', 'pending')
            ->first()->id;

        [$ok, $msg] = app(TeamTournamentService::class)->savePairs($this->tournament, [
            [$this->players[0]->id, $pendingId],
        ]);

        $this->assertFalse($ok, 'заявка может не подтвердиться — пара развалится');
        $this->assertStringContainsString('записаны на турнир', $msg);
    }

    public function test_автопары_тоже_не_ждут_полного_состава(): void
    {
        [$ok, $msg] = app(TeamTournamentService::class)->autoBalancePairs($this->tournament);

        $this->assertTrue($ok, $msg);
        // Пятеро подтверждённых — две пары, один остаётся без пары.
        $this->assertSame(2, $this->tournament->teams()->count());
    }

    public function test_пар_не_больше_чем_мест(): void
    {
        // Пятеро уже есть — добавим ещё пятерых: получится пять пар при
        // четырёх местах для пар.
        $extra = [];
        for ($i = 0; $i < 5; $i++) {
            $u = User::factory()->create();
            $this->tournament->participants()->attach($u->id, ['status' => 'registered']);
            $extra[] = $u;
        }

        $pairs = [];
        $all = array_merge($this->players, $extra);
        for ($i = 0; $i + 1 < count($all); $i += 2) {
            $pairs[] = [$all[$i]->id, $all[$i + 1]->id];
        }

        [$ok, $msg] = app(TeamTournamentService::class)->savePairs($this->tournament, $pairs);

        $this->assertFalse($ok, 'мест 8 — значит максимум 4 пары');
        $this->assertStringContainsString('максимум', $msg);
    }

    public function test_после_старта_пары_не_трогаем(): void
    {
        $this->tournament->teamGroups()->create(['name' => 'A']);

        [$ok, $msg] = app(TeamTournamentService::class)->savePairs($this->tournament, [
            [$this->players[0]->id, $this->players[1]->id],
        ]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('стартовал', $msg);
    }
}
