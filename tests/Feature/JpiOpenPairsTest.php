<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Парный «Just Padel It» собирается так же, как парный флекс.
 *
 * Раньше там требовалось привести партнёра при записи: человек без пары
 * записаться не мог вовсе. Теперь он садится первым, а рядом остаётся
 * свободное место — к нему подсаживается следующий.
 */
class JpiOpenPairsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'JUST PADEL IT', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function tournament(string $pairing = 'self'): Tournament
    {
        return Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => 'just_padel_it',
            'is_paired' => true,
            'pairing_mode' => $pairing,
            'open_pairs' => true,
            'status' => 'open',
            'max_participants' => 8,
            'courts_count' => 2,
            'min_level' => 0,
            'max_level' => 10,
        ]);
    }

    private function player(): User
    {
        return User::factory()->create(['level' => 3.0, 'rating' => 2000]);
    }

    public function test_парный_jpi_ходит_через_открытые_пары(): void
    {
        $t = $this->tournament();

        $this->assertTrue($t->usesOpenPairs(), 'пары открытые');
        $this->assertTrue($t->usesSoloRegistration(), 'записываются поодиночке');
        $this->assertTrue($t->usesPairGrid(), 'состав рисуется сеткой');
    }

    public function test_пары_собирает_админ_открытых_пар_нет(): void
    {
        $t = $this->tournament('admin');

        $this->assertFalse($t->usesOpenPairs());
        // У админского режима свой экран сбора пар — сетку туда не тащим,
        // иначе получилось бы два способа делать одно и то же.
        $this->assertFalse($t->usesPairGrid());
        $this->assertTrue($t->usesSoloRegistration(), 'записываются поодиночке');
    }

    /** Турниры, созданные до появления схемы, доигрывают прежним способом. */
    public function test_старый_турнир_без_флага_остаётся_на_записи_парой(): void
    {
        $t = $this->tournament();
        $t->update(['open_pairs' => false]);

        $this->assertFalse($t->fresh()->usesOpenPairs());
        $this->assertFalse($t->fresh()->usesSoloRegistration(), 'записываются парой, как раньше');
    }

    public function test_запись_открывает_пару_со_свободным_местом(): void
    {
        $t = $this->tournament();
        $first = $this->player();

        Sanctum::actingAs($first);
        $this->postJson("/api/mobile/tournaments/{$t->id}/register")
            ->assertOk()->assertJsonPath('success', true);

        $team = TournamentTeam::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame($first->id, $team->player1_id);
        $this->assertNull($team->player2_id, 'второе место ждёт партнёра');
    }

    public function test_второй_подсаживается_в_открытую_пару(): void
    {
        $t = $this->tournament();
        $first = $this->player();
        $second = $this->player();

        Sanctum::actingAs($first);
        $this->postJson("/api/mobile/tournaments/{$t->id}/register")->assertOk();
        $team = TournamentTeam::where('tournament_id', $t->id)->firstOrFail();

        Sanctum::actingAs($second);
        $this->postJson("/api/mobile/tournaments/{$t->id}/pairs/{$team->id}/join")
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame($second->id, $team->fresh()->player2_id);
    }

    public function test_сетка_пар_приходит_в_админку_приложения(): void
    {
        $t = $this->tournament();

        Sanctum::actingAs($this->admin);
        $grid = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/participants")
            ->assertOk()->json('flex_pairs');

        $this->assertNotNull($grid, 'админка рисует сетку, а не плоский список');
        $this->assertSame(4, $grid['max_pairs'], 'восемь мест — четыре пары');
    }

    public function test_игрок_видит_пары_в_карточке_турнира(): void
    {
        $t = $this->tournament();
        $first = $this->player();

        Sanctum::actingAs($first);
        $this->postJson("/api/mobile/tournaments/{$t->id}/register")->assertOk();

        $data = $this->getJson("/api/mobile/tournaments/{$t->id}")->assertOk()->json('tournament');

        $this->assertTrue($data['open_pairs']);
        $this->assertSame(4, $data['max_pairs']);
        $this->assertCount(1, $data['teams'], 'открытая пара видна игроку');
    }

    public function test_сетка_пар_есть_на_странице_турнира_в_вебе(): void
    {
        $t = $this->tournament();

        $html = $this->actingAs($this->admin)
            ->get("/club/tournaments/{$t->id}")
            ->assertOk()->getContent();

        // Партиал сетки узнаём по пустым местам: их рисует только он.
        $this->assertStringContainsString('flexp-seat', $html);
        $this->assertStringContainsString('Свободно', $html);
    }

    public function test_неполная_пара_не_даёт_стартовать(): void
    {
        $t = $this->tournament();

        // Четыре пары, но в одной пустует место.
        $players = collect(range(1, 7))->map(fn () => $this->player());
        foreach ($players->chunk(2) as $pair) {
            $list = $pair->values();
            TournamentTeam::create([
                'tournament_id' => $t->id,
                'player1_id' => $list[0]->id,
                'player2_id' => $list[1]->id ?? null,
                'status' => 'approved',
            ]);
            foreach ($list as $p) {
                $t->participants()->attach($p->id, ['status' => 'registered']);
            }
        }

        $this->assertSame(1, $t->incompleteFlexPairs());

        Sanctum::actingAs($this->admin);
        $canStart = $this->getJson("/api/mobile/admin/tournaments/{$t->id}")
            ->assertOk()->json('tournament.can_start');

        $this->assertFalse($canStart, 'пока место в паре пустует — не стартуем');
    }
}
