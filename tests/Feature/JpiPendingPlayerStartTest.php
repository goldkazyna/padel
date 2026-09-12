<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\JustPadelItService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Игрок одобренной пары не должен оставаться «на модерации».
 *
 * Живой случай: пара одобрена, а один из игроков висел `pending` — состав
 * считался как 11 из 12, не делился на 4, и турнир отказывался стартовать.
 * Поднять игрока было негде: в парном турнире отдельных заявок на экране нет.
 */
class JpiPendingPlayerStartTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tournament, 1: array<int, User>} */
    private function makeTournament(int $playersCount = 8): array
    {
        $club = Club::create(['name' => 'JUST PADEL IT', 'address' => 'А']);

        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'COUPLES', 'type' => 'just_padel_it',
            'status' => 'open', 'start_date' => now()->addDay()->toDateString(),
            'courts_count' => 2, 'max_participants' => 12,
            'is_rated' => false, 'is_paired' => true, 'pairing_mode' => 'self',
            'min_level' => 0, 'max_level' => 10,
        ]);

        $players = [];
        for ($i = 1; $i <= $playersCount; $i++) {
            $players[] = User::factory()->create(['name' => "P{$i}", 'rating' => 2000 - $i * 10]);
        }

        return [$t, $players];
    }

    /** Пара «записалась сама»: команда одобрена, участники уже в списке. */
    private function pair(Tournament $t, User $a, User $b, string $statusB = 'registered'): void
    {
        TournamentTeam::create([
            'tournament_id' => $t->id, 'player1_id' => $a->id, 'player2_id' => $b->id,
            'status' => 'approved',
        ]);
        $t->participants()->attach($a->id, ['status' => 'registered']);
        $t->participants()->attach($b->id, ['status' => $statusB]);
    }

    public function test_игрок_одобренной_пары_становится_участником(): void
    {
        [$t, $p] = $this->makeTournament();

        $this->pair($t, $p[0], $p[1], 'pending');   // тот самый застрявший
        $this->pair($t, $p[2], $p[3]);
        $this->pair($t, $p[4], $p[5]);
        $this->pair($t, $p[6], $p[7]);

        app(JustPadelItService::class)->syncPairsFromTeams($t);

        $this->assertSame('registered',
            $t->participants()->where('users.id', $p[1]->id)->first()->pivot->status);
    }

    public function test_турнир_стартует_несмотря_на_застрявшую_заявку(): void
    {
        [$t, $p] = $this->makeTournament();

        $this->pair($t, $p[0], $p[1], 'pending');
        $this->pair($t, $p[2], $p[3]);
        $this->pair($t, $p[4], $p[5]);
        $this->pair($t, $p[6], $p[7]);

        $this->assertTrue(app(JustPadelItService::class)->startTournament($t));
        $this->assertSame('in_progress', $t->fresh()->status);
    }

    public function test_заявка_без_пары_видна_организатору(): void
    {
        [$t, $p] = $this->makeTournament(9);

        $club = \App\Models\Club::first();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $this->pair($t, $p[0], $p[1]);
        $this->pair($t, $p[2], $p[3]);
        $t->participants()->attach($p[8]->id, ['status' => 'pending']);

        $html = $this->actingAs($admin)->get("/club/tournaments/{$t->id}")->assertOk()->getContent();

        $this->assertStringContainsString($p[8]->name, $html, 'игрок без пары виден');
        $this->assertStringContainsString('на модерации', $html);
        $this->assertStringContainsString(
            "/club/tournaments/{$t->id}/participants/{$p[8]->id}", $html, 'есть чем убрать');
    }

    public function test_лишний_игрок_без_пары_не_ломает_старт(): void
    {
        [$t, $p] = $this->makeTournament(9);

        $this->pair($t, $p[0], $p[1], 'pending');
        $this->pair($t, $p[2], $p[3]);
        $this->pair($t, $p[4], $p[5]);
        $this->pair($t, $p[6], $p[7]);
        // Девятый подал заявку, пары ему не нашлось — он остаётся ждать.
        $t->participants()->attach($p[8]->id, ['status' => 'pending']);

        $this->assertTrue(app(JustPadelItService::class)->startTournament($t));
        $this->assertSame('pending',
            $t->participants()->where('users.id', $p[8]->id)->first()->pivot->status);
    }
}
