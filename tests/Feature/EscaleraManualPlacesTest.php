<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\EscaleraPlayer;
use App\Models\EscaleraRound;
use App\Models\EscaleraRoundCourt;
use App\Models\Tournament;
use App\Models\User;
use App\Services\EscaleraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Ручная правка мест на корте в Ladder.
 *
 * Ничью по очкам код решает рейтингом, но вверх по лестнице едет только
 * первый — организатору нужен способ рассудить иначе, пока раунд не закрыт.
 */
class EscaleraManualPlacesTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EscaleraService
    {
        return app(EscaleraService::class);
    }

    /**
     * Турнир на два корта, первый раунд сыгран вничью по очкам на корте 2:
     * каждый матч 6:6, значит у всех четверых по 18 очков и порядок решает
     * рейтинг — ровно та ситуация, из-за которой нужна ручная правка.
     *
     * @return array{0: Tournament, 1: User, 2: array<string, User>}
     */
    private function scenario(): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $tournament = Tournament::create([
            'club_id' => $club->id,
            'name' => 'Эскалера',
            'type' => 'escalera',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'courts_count' => 2,
            'max_participants' => 8,
            'min_level' => 1,
            'max_level' => 5,
            'is_rated' => true,
        ]);

        $users = [];
        $rating = 2000;
        foreach (range(1, 8) as $i) {
            $user = User::factory()->create(['name' => "И{$i}", 'rating' => $rating]);
            $tournament->participants()->attach($user->id, ['status' => 'registered']);
            $users["И{$i}"] = $user;
            $rating -= 100;
        }

        $this->service()->startTournament($tournament);

        // Все матчи вничью — очки у всех на корте равны.
        $round = $tournament->escaleraRounds()->reorder('round_number', 'desc')->first();
        foreach ($round->courts()->with('matches')->get() as $court) {
            foreach ($court->matches as $match) {
                $this->service()->saveMatchResult($match, 6, 6);
            }
        }

        return [$tournament->fresh(), $admin, $users];
    }

    private function court(Tournament $tournament, int $number): EscaleraRoundCourt
    {
        return $tournament->escaleraRounds()->reorder('round_number', 'desc')->first()
            ->courts()->where('court_number', $number)->firstOrFail();
    }

    /** Имена игроков корта в порядке мест. */
    private function placeNames(EscaleraRoundCourt $court): array
    {
        $order = $this->service()->rankCourt($court);
        $names = User::whereIn('id', $order)->pluck('name', 'id');

        return array_map(fn ($id) => $names[$id], $order);
    }

    public function test_tie_is_broken_by_rating_by_default(): void
    {
        [$t] = $this->scenario();
        $court = $this->court($t, 1);

        $order = $this->service()->rankCourt($court);
        $ratings = User::whereIn('id', $order)->pluck('rating', 'id');
        $inOrder = array_map(fn ($id) => (int) $ratings[$id], $order);

        $this->assertSame($inOrder, collect($inOrder)->sortDesc()->values()->all(),
            'при равных очках выше идёт игрок с большим рейтингом');
    }

    public function test_admin_lifts_player_one_place(): void
    {
        [$t, $admin] = $this->scenario();
        $court = $this->court($t, 1);
        [$first, $second] = $this->placeNames($court);

        $this->actingAs($admin)
            ->post(route('club.escalera.moveCourtPlace', $court), [
                'user_id' => $this->service()->rankCourt($court)[1],
                'direction' => 'up',
            ])->assertRedirect();

        $this->assertSame([$second, $first], array_slice($this->placeNames($court->fresh()), 0, 2),
            'второй поднялся на первое место');
    }

    public function test_lifted_player_is_the_one_who_moves_up_the_ladder(): void
    {
        [$t, $admin] = $this->scenario();
        // Корт 2 — не верхний, с него первое место едет вверх.
        $court = $this->court($t, 2);
        $secondPlaceId = $this->service()->rankCourt($court)[1];

        $this->actingAs($admin)->post(route('club.escalera.moveCourtPlace', $court), [
            'user_id' => $secondPlaceId,
            'direction' => 'up',
        ])->assertRedirect();

        $preview = collect($this->service()->previewRoundClose($t->fresh()))
            ->firstWhere('court_number', 2);
        $top = collect($preview['places'])->firstWhere('place', 1);

        $this->assertSame($secondPlaceId, $top['user_id']);
        $this->assertSame('up', $top['movement'], 'наверх едет тот, кого подняли');
    }

    public function test_manual_order_survives_round_close(): void
    {
        [$t, $admin] = $this->scenario();
        $court = $this->court($t, 2);
        $secondPlaceId = $this->service()->rankCourt($court)[1];

        $this->actingAs($admin)->post(route('club.escalera.moveCourtPlace', $court), [
            'user_id' => $secondPlaceId,
            'direction' => 'up',
        ])->assertRedirect();

        $this->assertTrue($this->service()->closeRound($t->fresh()));

        $this->assertDatabaseHas('escalera_round_results', [
            'user_id' => $secondPlaceId,
            'court_number' => 2,
            'place_on_court' => 1,
        ]);
        // С корта 2 первый едет на корт 1.
        $this->assertSame(1, (int) EscaleraPlayer::where('tournament_id', $t->id)
            ->where('user_id', $secondPlaceId)->value('current_court'));
    }

    public function test_moving_down_from_last_place_changes_nothing(): void
    {
        [$t, $admin] = $this->scenario();
        $court = $this->court($t, 1);
        $before = $this->placeNames($court);

        $this->actingAs($admin)->post(route('club.escalera.moveCourtPlace', $court), [
            'user_id' => $this->service()->rankCourt($court)[3],
            'direction' => 'down',
        ])->assertRedirect();

        $this->assertSame($before, $this->placeNames($court->fresh()));
    }

    public function test_reset_returns_computed_order(): void
    {
        [$t, $admin] = $this->scenario();
        $court = $this->court($t, 1);
        $computed = $this->placeNames($court);

        $this->actingAs($admin)->post(route('club.escalera.moveCourtPlace', $court), [
            'user_id' => $this->service()->rankCourt($court)[1],
            'direction' => 'up',
        ])->assertRedirect();
        $this->assertNotSame($computed, $this->placeNames($court->fresh()));

        $this->actingAs($admin)
            ->post(route('club.escalera.resetCourtPlaces', $court))
            ->assertRedirect();

        $this->assertSame($computed, $this->placeNames($court->fresh()));
    }

    public function test_score_edit_drops_manual_order(): void
    {
        [$t, $admin] = $this->scenario();
        $court = $this->court($t, 1);

        $this->actingAs($admin)->post(route('club.escalera.moveCourtPlace', $court), [
            'user_id' => $this->service()->rankCourt($court)[1],
            'direction' => 'up',
        ])->assertRedirect();
        $this->assertNotNull($court->fresh()->manual_rank);

        // Правка счёта меняет расклад — ручной порядок больше ни о чём не говорит.
        $this->service()->saveMatchResult($court->matches()->first(), 12, 0);

        $this->assertNull($court->fresh()->manual_rank);
    }

    public function test_foreign_player_is_rejected(): void
    {
        [$t, $admin] = $this->scenario();
        $court = $this->court($t, 1);
        $stranger = $this->service()->rankCourt($this->court($t, 2))[0];

        $this->actingAs($admin)
            ->post(route('club.escalera.moveCourtPlace', $court), [
                'user_id' => $stranger,
                'direction' => 'up',
            ])->assertRedirect()->assertSessionHas('error');

        $this->assertNull($court->fresh()->manual_rank);
    }

    public function test_service_rejects_order_with_wrong_players(): void
    {
        [$t] = $this->scenario();
        $court = $this->court($t, 1);
        $stranger = $this->service()->rankCourt($this->court($t, 2))[0];

        $this->expectException(InvalidArgumentException::class);
        $this->service()->setCourtOrder($court, [$stranger, ...array_slice($court->playerIds(), 1)]);
    }

    public function test_closed_round_cannot_be_reordered(): void
    {
        [$t] = $this->scenario();
        $court = $this->court($t, 1);
        $this->service()->closeRound($t);

        $this->expectException(\RuntimeException::class);
        $this->service()->setCourtOrder($court->fresh(), array_reverse($court->playerIds()));
    }

    public function test_preview_shows_arrows_and_manual_badge(): void
    {
        [$t, $admin] = $this->scenario();
        $court = $this->court($t, 1);

        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $t))
            ->assertOk()
            ->assertSee('Поднять')
            ->assertDontSee('места заданы вручную');

        $this->actingAs($admin)->post(route('club.escalera.moveCourtPlace', $court), [
            'user_id' => $this->service()->rankCourt($court)[1],
            'direction' => 'up',
        ]);

        $this->actingAs($admin)
            ->get(route('club.tournaments.show', $t))
            ->assertOk()
            ->assertSee('места заданы вручную');
    }
}
