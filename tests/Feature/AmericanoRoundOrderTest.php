<?php

namespace Tests\Feature;

use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\User;
use App\Services\AmericanoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Счёт вносят в том порядке, в каком его приносят с кортов.
 *
 * На турнире 1278 раунд 3 сыграли и записали раньше, чем внесли счёт раунда 2.
 * Закрытие второго раунда откатывало третий обратно в «идёт», и кнопка
 * «Завершить турнир» больше не появлялась: она требует все раунды сыгранными.
 */
class AmericanoRoundOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tournament $tournament;
    private TournamentGroup $group;
    private array $players;

    protected function setUp(): void
    {
        parent::setUp();

        $club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->tournament = Tournament::create([
            'club_id' => $club->id, 'name' => 'Американо', 'type' => 'americano',
            'status' => 'in_progress', 'is_rated' => true, 'start_date' => now()->subHour(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 4,
        ]);

        $this->group = TournamentGroup::create([
            'tournament_id' => $this->tournament->id, 'name' => 'A', 'group_number' => 1,
        ]);

        $this->players = User::factory()->count(4)->create()->all();
        foreach ($this->players as $p) {
            $this->group->players()->attach($p->id);
        }
    }

    private function round(int $number, string $status): AmericanoRound
    {
        return AmericanoRound::create([
            'tournament_group_id' => $this->group->id,
            'round_number' => $number,
            'status' => $status,
        ]);
    }

    private function match(AmericanoRound $round): AmericanoMatch
    {
        return AmericanoMatch::create([
            'americano_round_id' => $round->id,
            'court_number' => 1,
            'team1_player1_id' => $this->players[0]->id,
            'team1_player2_id' => $this->players[1]->id,
            'team2_player1_id' => $this->players[2]->id,
            'team2_player2_id' => $this->players[3]->id,
            'status' => 'pending',
        ]);
    }

    public function test_доигранный_вперёд_раунд_не_открывается_заново(): void
    {
        $service = app(AmericanoService::class);

        $second = $this->round(2, 'in_progress');
        $third = $this->round(3, 'in_progress');
        $fourth = $this->round(4, 'pending');

        $secondMatch = $this->match($second);
        $thirdMatch = $this->match($third);

        // Сначала записали третий раунд — он закрылся сам.
        $service->saveMatchResult($thirdMatch, 18, 6);
        $this->assertSame('completed', $third->fresh()->status);

        // Потом донесли счёт второго. Третий должен остаться сыгранным.
        $service->saveMatchResult($secondMatch, 15, 9);

        $this->assertSame('completed', $second->fresh()->status);
        $this->assertSame('completed', $third->fresh()->status, 'раунд уже доигран');
        $this->assertSame('in_progress', $fourth->fresh()->status, 'открылся следующий несыгранный');
    }

    public function test_обычный_порядок_открывает_следующий_раунд(): void
    {
        $service = app(AmericanoService::class);

        $first = $this->round(1, 'in_progress');
        $second = $this->round(2, 'pending');

        $service->saveMatchResult($this->match($first), 21, 12);

        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('in_progress', $second->fresh()->status);
    }

    public function test_цепочка_доигранных_раундов_закрывается_целиком(): void
    {
        $service = app(AmericanoService::class);

        $first = $this->round(1, 'in_progress');
        $second = $this->round(2, 'in_progress');
        $third = $this->round(3, 'in_progress');
        $fourth = $this->round(4, 'pending');

        $firstMatch = $this->match($first);
        $secondMatch = $this->match($second);
        $thirdMatch = $this->match($third);

        // Второй и третий доиграли раньше, чем внесли счёт первого.
        $service->saveMatchResult($secondMatch, 16, 8);
        $service->saveMatchResult($thirdMatch, 14, 10);
        $service->saveMatchResult($firstMatch, 21, 12);

        foreach ([$first, $second, $third] as $round) {
            $this->assertSame('completed', $round->fresh()->status);
        }
        $this->assertSame('in_progress', $fourth->fresh()->status);
    }

    public function test_правка_счёта_не_переоткрывает_сыгранное(): void
    {
        $service = app(AmericanoService::class);

        $first = $this->round(1, 'in_progress');
        $second = $this->round(2, 'in_progress');

        $firstMatch = $this->match($first);
        $secondMatch = $this->match($second);

        $service->saveMatchResult($firstMatch, 21, 12);
        $service->saveMatchResult($secondMatch, 16, 8);

        // Организатор поправил счёт первого раунда задним числом.
        $service->updateMatchResult($firstMatch->fresh(), 20, 13);

        $this->assertSame('completed', $second->fresh()->status);
    }
}
