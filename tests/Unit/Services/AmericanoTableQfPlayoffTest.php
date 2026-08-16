<?php

namespace Tests\Unit\Services;

use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\TournamentParticipant;
use App\Models\TournamentPlayoffMatch;
use App\Models\User;
use App\Services\AmericanoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Плей-офф Американо по общей таблице — для трёх групп и больше.
 *
 * Обычные форматы разводят пары по группам A и B, поэтому третья группа
 * в сетку не попадала вовсе. Здесь все группы складываются в один ряд:
 * места 1–4 ждут в полуфинале, места 5–12 играют четвертьфинал.
 */
class AmericanoTableQfPlayoffTest extends TestCase
{
    use RefreshDatabase;

    private AmericanoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AmericanoService();
    }

    /**
     * Турнир на 24 игрока в трёх группах. Очки раздаются так, чтобы общий ряд
     * был известен заранее: игрок с именем «M1» — первое место таблицы и т.д.
     *
     * @return array{0: Tournament, 1: array<int, User>} турнир и игроки по местам (с нуля)
     */
    private function makeFinishedThreeGroups(int $players = 24, array $attrs = []): array
    {
        $tournament = Tournament::factory()->create(array_merge([
            'type' => 'americano',
            'status' => 'in_progress',
            'groups_count' => 3,
            'max_participants' => $players,
            'rounds_count' => 1,
            'has_playoff' => true,
            'playoff_type' => 'semifinal_final',
            'playoff_format' => Tournament::PLAYOFF_FORMAT_TABLE_QF,
        ], $attrs));

        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $groups[$g] = TournamentGroup::create([
                'tournament_id' => $tournament->id,
                'name' => 'Группа ' . chr(65 + $g),
            ]);
        }

        // Место в общей таблице задаётся очками, группа — по кругу,
        // чтобы лидеры таблицы оказались в разных группах.
        $byPlace = [];
        for ($i = 0; $i < $players; $i++) {
            $user = User::factory()->create(['rating' => 1500, 'name' => 'M' . ($i + 1)]);
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);
            $groups[$i % 3]->players()->attach($user->id, [
                'total_points' => ($players - $i) * 10,
                'rating_before' => 1500,
                'rating_after' => null,
            ]);
            $byPlace[$i] = $user;
        }

        return [$tournament->fresh(), $byPlace];
    }

    /** Пара матча как множество id — порядок игроков внутри пары не важен. */
    private function team(TournamentPlayoffMatch $m, int $side): array
    {
        return $side === 1
            ? [$m->team1_player1_id, $m->team1_player2_id]
            : [$m->team2_player1_id, $m->team2_player2_id];
    }

    private function stage(Tournament $t, string $stage, ?int $number = null)
    {
        $q = TournamentPlayoffMatch::where('tournament_id', $t->id)->where('stage', $stage);
        if ($number !== null) {
            $q->where('match_number', $number);
        }
        return $number === null ? $q->orderBy('match_number')->get() : $q->first();
    }

    public function test_three_groups_build_quarterfinals_and_waiting_semifinals(): void
    {
        [$t] = $this->makeFinishedThreeGroups();

        $this->assertTrue($this->service->generatePlayoff($t));

        $this->assertCount(2, $this->stage($t, 'Четвертьфинал'), 'два четвертьфинала');
        $this->assertCount(2, $this->stage($t, 'Полуфинал'), 'два полуфинала');
        $this->assertCount(1, $this->stage($t, 'Финал'), 'один финал');
    }

    public function test_top_four_wait_in_semifinals_paired_by_snake(): void
    {
        [$t, $p] = $this->makeFinishedThreeGroups();
        $this->service->generatePlayoff($t);

        $semi1 = $this->stage($t, 'Полуфинал', 1);
        $semi2 = $this->stage($t, 'Полуфинал', 2);

        // Змейка: сильнейший со слабейшим из четвёрки, чтобы пары вышли ровными.
        $this->assertEqualsCanonicalizing([$p[0]->id, $p[3]->id], $this->team($semi1, 1), '1+4 ждут в ПФ 1');
        $this->assertEqualsCanonicalizing([$p[1]->id, $p[2]->id], $this->team($semi2, 1), '2+3 ждут в ПФ 2');

        $this->assertNull($semi1->team2_player1_id, 'соперник ПФ 1 ещё не известен');
        $this->assertNull($semi2->team2_player1_id, 'соперник ПФ 2 ещё не известен');
        $this->assertSame('Победитель ЧФ 2', $semi1->team2_source);
        $this->assertSame('Победитель ЧФ 1', $semi2->team2_source);
    }

    public function test_places_five_to_twelve_play_quarterfinals(): void
    {
        [$t, $p] = $this->makeFinishedThreeGroups();
        $this->service->generatePlayoff($t);

        $qf1 = $this->stage($t, 'Четвертьфинал', 1);
        $qf2 = $this->stage($t, 'Четвертьфинал', 2);

        $this->assertEqualsCanonicalizing([$p[4]->id, $p[11]->id], $this->team($qf1, 1), 'ЧФ 1: 5+12');
        $this->assertEqualsCanonicalizing([$p[7]->id, $p[8]->id], $this->team($qf1, 2), 'ЧФ 1: 8+9');
        $this->assertEqualsCanonicalizing([$p[5]->id, $p[10]->id], $this->team($qf2, 1), 'ЧФ 2: 6+11');
        $this->assertEqualsCanonicalizing([$p[6]->id, $p[9]->id], $this->team($qf2, 2), 'ЧФ 2: 7+10');

        // Каждый из мест 5–12 играет ровно один четвертьфинал.
        $inQf = collect([$qf1, $qf2])->flatMap(fn($m) => array_merge($this->team($m, 1), $this->team($m, 2)));
        $this->assertCount(8, $inQf->unique(), 'восемь разных игроков');
        $this->assertEqualsCanonicalizing(
            collect(range(4, 11))->map(fn($i) => $p[$i]->id)->all(),
            $inQf->all()
        );
    }

    public function test_places_thirteen_and_below_stay_out_of_playoff(): void
    {
        [$t, $p] = $this->makeFinishedThreeGroups();
        $this->service->generatePlayoff($t);

        $ids = TournamentPlayoffMatch::where('tournament_id', $t->id)->get()
            ->flatMap(fn($m) => [
                $m->team1_player1_id, $m->team1_player2_id,
                $m->team2_player1_id, $m->team2_player2_id,
            ])->filter()->unique();

        $this->assertCount(12, $ids, 'в сетке ровно 12 игроков');
        $this->assertNotContains($p[12]->id, $ids->all(), '13-е место в плей-офф не идёт');
    }

    public function test_quarterfinal_winner_goes_to_the_far_semifinal(): void
    {
        [$t, $p] = $this->makeFinishedThreeGroups();
        $this->service->generatePlayoff($t);

        $qf1 = $this->stage($t, 'Четвертьфинал', 1);
        $qf1->update(['team1_score' => 21, 'team2_score' => 15, 'status' => 'completed']);
        $this->service->advancePlayoff($qf1->fresh());

        // Победитель ЧФ 1 — пара 5+12 — уходит в ПФ 2, к паре 2+3.
        $semi2 = $this->stage($t, 'Полуфинал', 2);
        $this->assertEqualsCanonicalizing([$p[4]->id, $p[11]->id], $this->team($semi2, 2));

        $semi1 = $this->stage($t, 'Полуфинал', 1);
        $this->assertNull($semi1->team2_player1_id, 'ПФ 1 ждёт свой четвертьфинал');
    }

    public function test_losing_side_of_quarterfinal_advances_when_it_scores_more(): void
    {
        [$t, $p] = $this->makeFinishedThreeGroups();
        $this->service->generatePlayoff($t);

        $qf2 = $this->stage($t, 'Четвертьфинал', 2);
        $qf2->update(['team1_score' => 10, 'team2_score' => 21, 'status' => 'completed']);
        $this->service->advancePlayoff($qf2->fresh());

        // Победила вторая пара — 7+10, идёт в ПФ 1.
        $semi1 = $this->stage($t, 'Полуфинал', 1);
        $this->assertEqualsCanonicalizing([$p[6]->id, $p[9]->id], $this->team($semi1, 2));
    }

    public function test_final_fills_after_both_semifinals(): void
    {
        [$t, $p] = $this->makeFinishedThreeGroups();
        $this->service->generatePlayoff($t);

        foreach ([1, 2] as $n) {
            $qf = $this->stage($t, 'Четвертьфинал', $n);
            $qf->update(['team1_score' => 21, 'team2_score' => 12, 'status' => 'completed']);
            $this->service->advancePlayoff($qf->fresh());
        }

        $final = $this->stage($t, 'Финал', 1);
        $this->assertNull($final->team1_player1_id, 'финал пуст, пока полуфиналы не сыграны');

        foreach ([1, 2] as $n) {
            $semi = $this->stage($t, 'Полуфинал', $n);
            $semi->update(['team1_score' => 21, 'team2_score' => 14, 'status' => 'completed']);
            $this->service->advancePlayoff($semi->fresh());
        }

        $final = $this->stage($t, 'Финал', 1);
        $this->assertEqualsCanonicalizing([$p[0]->id, $p[3]->id], $this->team($final, 1), 'пара 1+4 выиграла ПФ 1');
        $this->assertEqualsCanonicalizing([$p[1]->id, $p[2]->id], $this->team($final, 2), 'пара 2+3 выиграла ПФ 2');
    }

    public function test_bronze_match_is_created_and_filled_by_semifinal_losers(): void
    {
        [$t, $p] = $this->makeFinishedThreeGroups(24, ['has_bronze_match' => true]);
        $this->service->generatePlayoff($t);

        $bronze = TournamentPlayoffMatch::where('tournament_id', $t->id)->where('is_bronze', true)->first();
        $this->assertNotNull($bronze, 'матч за 3-е место создан');

        foreach ([1, 2] as $n) {
            $qf = $this->stage($t, 'Четвертьфинал', $n);
            $qf->update(['team1_score' => 21, 'team2_score' => 12, 'status' => 'completed']);
            $this->service->advancePlayoff($qf->fresh());
            $semi = $this->stage($t, 'Полуфинал', $n);
            $semi->update(['team1_score' => 21, 'team2_score' => 14, 'status' => 'completed']);
            $this->service->advancePlayoff($semi->fresh());
        }

        $bronze->refresh();
        // Полуфиналы проиграли победители четвертьфиналов; порядок — по номеру ПФ:
        // ПФ 1 отдаёт победителя ЧФ 2 (6+11), ПФ 2 — победителя ЧФ 1 (5+12).
        $this->assertEqualsCanonicalizing([$p[5]->id, $p[10]->id], $this->team($bronze, 1));
        $this->assertEqualsCanonicalizing([$p[4]->id, $p[11]->id], $this->team($bronze, 2));
    }

    public function test_two_groups_keep_the_old_bracket(): void
    {
        [$t] = $this->makeFinishedThreeGroups();
        // Возвращаем турнир к двум группам: третья группа расформирована.
        $t->groups()->where('name', 'Группа C')->each(function ($g) {
            $g->players()->detach();
            $g->delete();
        });
        $t->update(['groups_count' => 2, 'playoff_format' => 'mix']);

        $this->assertTrue($this->service->generatePlayoff($t->fresh()));
        $this->assertCount(0, $this->stage($t, 'Четвертьфинал'), 'при двух группах четвертьфинала нет');
        $this->assertCount(2, $this->stage($t, 'Полуфинал'));
    }

    public function test_three_groups_need_twelve_players(): void
    {
        // Девять игроков на три группы — сетку из 12 мест не собрать.
        [$t] = $this->makeFinishedThreeGroups(9);

        $this->assertFalse($this->service->generatePlayoff($t), 'плей-офф не строится');
        $this->assertCount(0, $this->stage($t, 'Четвертьфинал'));
        $this->assertCount(0, $this->stage($t, 'Полуфинал'));
    }

    public function test_draw_in_quarterfinal_leaves_semifinal_empty(): void
    {
        [$t] = $this->makeFinishedThreeGroups();
        $this->service->generatePlayoff($t);

        $qf1 = $this->stage($t, 'Четвертьфинал', 1);
        $qf1->update(['team1_score' => 15, 'team2_score' => 15, 'status' => 'completed']);
        $this->service->advancePlayoff($qf1->fresh());

        $this->assertNull($this->stage($t, 'Полуфинал', 2)->team2_player1_id, 'победителя нет — место свободно');
    }
}
