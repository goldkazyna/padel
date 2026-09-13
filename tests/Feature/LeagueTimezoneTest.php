<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\League;
use App\Models\Tournament;
use App\Models\User;
use App\Support\ClubTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Даты лиги уходят в приложение в часах клуба.
 *
 * В базе они местные (Алматы), а `app.timezone` = UTC: без смещения метка
 * «20:00+00:00» превращалась в приложении в 01:00 следующего дня, а старт
 * лиги 1 сентября 21:01 показывался вторым сентября.
 */
class LeagueTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private League $league;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $club = Club::create(['name' => 'Davay Padel', 'address' => 'А', 'city' => 'Алматы']);
        $this->user = User::factory()->create(['level' => 3.0]);

        $this->league = League::create([
            'club_id' => $club->id,
            'name' => 'Сентябрьская лига',
            'status' => 'in_progress',
            'start_date' => '2026-09-01 21:01:00',
            'end_date' => '2026-09-24 21:01:00',
            'stages_planned' => 2,
        ]);

        Tournament::factory()->create([
            'club_id' => $club->id,
            'league_id' => $this->league->id,
            'league_stage' => 1,
            'type' => 'americano_flex',
            'status' => 'open',
            'start_date' => '2026-09-15 20:00:00',
        ]);
    }

    public function test_даты_лиги_приходят_со_смещением_клуба(): void
    {
        Sanctum::actingAs($this->user);

        $league = $this->getJson("/api/mobile/leagues/{$this->league->id}")
            ->assertOk()->json('league');

        $this->assertSame('2026-09-01T21:01:00+05:00', $league['start_date'],
            'старт лиги должен остаться первым сентября');
        $this->assertSame('2026-09-24T21:01:00+05:00', $league['end_date']);
        $this->assertSame('2026-09-15T20:00:00+05:00', $league['next_stage']['start_date']);
    }

    public function test_помощник_не_двигает_часы(): void
    {
        // 20:00 в базе — 20:00 и в метке, просто с честным смещением.
        $iso = ClubTime::iso(\Carbon\Carbon::parse('2026-09-15 20:00:00'));

        $this->assertSame('2026-09-15T20:00:00+05:00', $iso);
        $this->assertNull(ClubTime::iso(null));
        $this->assertSame('Asia/Almaty', ClubTime::now()->timezoneName);
    }
}
