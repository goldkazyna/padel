<?php

namespace Tests\Feature;

use App\Achievements\AchievementRegistry;
use App\Achievements\PlayerHistory;
use App\Achievements\Rules\Clubs3;
use App\Achievements\Rules\Duo10;
use App\Achievements\Rules\FirstWin;
use App\Achievements\Rules\Flawless;
use App\Achievements\Rules\Formats5;
use App\Achievements\Rules\LevelUp;
use App\Achievements\Rules\Streak5;
use App\Models\RatingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Правила значков на готовом снимке истории.
 *
 * Простые счётчики турниров проверяются составом реестра, здесь — те,
 * что считают по истории и где легко ошибиться.
 */
class AchievementRulesTest extends TestCase
{
    use RefreshDatabase;

    private function history(array $matches, $ratingEntries = null, array $stats = []): PlayerHistory
    {
        return new PlayerHistory(
            User::factory()->create(),
            $matches,
            $ratingEntries ?? collect(),
            array_merge(['total' => 0, 'wins' => 0, 'by_type' => []], $stats),
        );
    }

    private function match(array $over = []): array
    {
        return array_merge([
            'id' => 1,
            'tournament_id' => 1,
            'tournament_type' => 'americano',
            'club_id' => 1,
            'result' => 'win',
            'partner' => ['id' => 99, 'name' => 'П', 'avatar' => null],
            'sort_date' => 1,
        ], $over);
    }

    public function test_first_win_needs_exactly_one_won_match(): void
    {
        $rule = new FirstWin();

        $this->assertSame(0, $rule->progress($this->history([
            $this->match(['result' => 'loss']),
            $this->match(['result' => 'draw']),
        ])));
        $this->assertSame(1, $rule->progress($this->history([
            $this->match(['result' => 'loss']),
            $this->match(),
        ])));
        $this->assertSame(1, $rule->progress($this->history([
            $this->match(), $this->match(),
        ])), 'выше цели прогресс не растёт');
    }

    public function test_streak_breaks_on_any_non_win(): void
    {
        $rule = new Streak5();

        $wins = array_map(fn ($i) => $this->match(['sort_date' => $i]), range(1, 5));
        $this->assertSame(5, $rule->progress($this->history($wins)));

        // Ничья прерывает серию так же, как поражение.
        $broken = [
            $this->match(['sort_date' => 1]),
            $this->match(['sort_date' => 2]),
            $this->match(['sort_date' => 3, 'result' => 'draw']),
            $this->match(['sort_date' => 4]),
            $this->match(['sort_date' => 5]),
        ];
        $this->assertSame(2, $rule->progress($this->history($broken)));
    }

    public function test_flawless_needs_every_match_of_one_tournament_won(): void
    {
        $rule = new Flawless();

        $mixed = [
            $this->match(['tournament_id' => 1]),
            $this->match(['tournament_id' => 1, 'result' => 'loss']),
        ];
        $this->assertSame(0, $rule->progress($this->history($mixed)));

        // Ничья тоже ломает «без потерь».
        $withDraw = [
            $this->match(['tournament_id' => 2]),
            $this->match(['tournament_id' => 2, 'result' => 'draw']),
        ];
        $this->assertSame(0, $rule->progress($this->history($withDraw)));

        $clean = [
            $this->match(['tournament_id' => 3]),
            $this->match(['tournament_id' => 3]),
        ];
        $this->assertSame(1, $rule->progress($this->history($clean)));
    }

    public function test_formats_count_tournament_type_not_stage(): void
    {
        $rule = new Formats5();

        // Классический турнир даёт матчи групп и плей-офф — это один формат.
        $matches = [
            $this->match(['tournament_type' => 'classic', 'tournament_id' => 1]),
            $this->match(['tournament_type' => 'classic', 'tournament_id' => 1]),
            $this->match(['tournament_type' => 'americano', 'tournament_id' => 2]),
        ];

        $this->assertSame(2, $rule->progress($this->history($matches)));
    }

    public function test_знаток_форматов_не_считает_bali_и_классический(): void
    {
        $rule = new \App\Achievements\Rules\FormatsAll();

        // Клубы их не проводят: с ними значок был недостижим.
        $this->assertSame(7, $rule->target());

        $matches = [
            $this->match(['tournament_type' => 'bali_koc', 'tournament_id' => 1]),
            $this->match(['tournament_type' => 'classic', 'tournament_id' => 2]),
            $this->match(['tournament_type' => 'americano', 'tournament_id' => 3]),
        ];

        $this->assertSame(1, $rule->progress($this->history($matches)));
    }

    public function test_знаток_форматов_не_считает_round_robin(): void
    {
        $rule = new \App\Achievements\Rules\FormatsAll();

        // Round Robin — вариант «Короля корта», а не отдельный формат.
        $this->assertNotContains('round_robin', \App\Achievements\Rules\FormatsAll::COUNTED);

        $matches = [
            $this->match(['tournament_type' => 'round_robin', 'tournament_id' => 1]),
            $this->match(['tournament_type' => 'americano', 'tournament_id' => 2]),
        ];

        $this->assertSame(1, $rule->progress($this->history($matches)));
    }

    public function test_знаток_форматов_закрывается_семью(): void
    {
        $rule = new \App\Achievements\Rules\FormatsAll();

        $matches = [];
        foreach (\App\Achievements\Rules\FormatsAll::COUNTED as $i => $type) {
            $matches[] = $this->match(['tournament_type' => $type, 'tournament_id' => $i + 1]);
        }

        $this->assertSame(7, $rule->progress($this->history($matches)));
    }

    public function test_значки_за_мячи_считают_забитое(): void
    {
        $rule = new \App\Achievements\Rules\Points500();

        $matches = [
            $this->match(['points_for' => 300, 'points_against' => 100]),
            $this->match(['points_for' => 150, 'points_against' => 200]),
        ];

        // Считаем только свои очки, независимо от результата матча.
        $this->assertSame(450, $rule->progress($this->history($matches)));
    }

    public function test_пороги_за_мячи_растут_с_металлом(): void
    {
        // Пороги взяты из живого распределения: 500 — у 29% игроков,
        // 1700 — у 10%, 5000 — у 1%.
        $bronze = new \App\Achievements\Rules\Points500();
        $silver = new \App\Achievements\Rules\Points1700();
        $gold = new \App\Achievements\Rules\Points5000();

        $this->assertSame([500, 1700, 5000], [$bronze->target(), $silver->target(), $gold->target()]);
        $this->assertSame(['bronze', 'silver', 'gold'], [$bronze->tier(), $silver->tier(), $gold->tier()]);
        $this->assertSame('Забить 1700 мячей', $silver->description());

        // Значок не перепрыгивает свой потолок.
        $many = [$this->match(['points_for' => 9000])];
        $this->assertSame(500, $bronze->progress($this->history($many)));
    }

    public function test_clubs_counted_by_id(): void
    {
        $rule = new Clubs3();
        $matches = [
            $this->match(['club_id' => 1]),
            $this->match(['club_id' => 2]),
            $this->match(['club_id' => 2]),
            $this->match(['club_id' => null]),
        ];

        $this->assertSame(2, $rule->progress($this->history($matches)));
    }

    public function test_duo_counts_the_most_frequent_partner(): void
    {
        $rule = new Duo10();
        $matches = [
            $this->match(['partner' => ['id' => 1, 'name' => 'А', 'avatar' => null]]),
            $this->match(['partner' => ['id' => 1, 'name' => 'А', 'avatar' => null]]),
            $this->match(['partner' => ['id' => 2, 'name' => 'Б', 'avatar' => null]]),
            $this->match(['partner' => null]),
        ];

        $this->assertSame(2, $rule->progress($this->history($matches)));
    }

    public function test_level_up_compares_against_starting_level(): void
    {
        $rule = new LevelUp();
        $user = User::factory()->create();

        // 1000 → уровень 1.0, 1300 → уровень 1.25.
        $grew = collect([
            new RatingHistory(['user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300, 'change' => 300]),
        ]);
        $this->assertSame(1, $rule->progress($this->history([], $grew)));

        $flat = collect([
            new RatingHistory(['user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1100, 'change' => 100]),
        ]);
        $this->assertSame(0, $rule->progress($this->history([], $flat)), '1100 — тот же уровень 1.0');
    }

    public function test_registry_holds_all_rules_with_unique_codes(): void
    {
        $rules = app(AchievementRegistry::class)->all();
        $codes = array_map(fn ($r) => $r->code(), $rules);

        $this->assertCount(18, $rules);
        $this->assertSame($codes, array_unique($codes), 'коды значков не должны повторяться');
        $this->assertInstanceOf(FirstWin::class, app(AchievementRegistry::class)->byCode('first_win'));
        $this->assertNull(app(AchievementRegistry::class)->byCode('нет такого'));
    }

    public function test_every_rule_is_zero_on_empty_history(): void
    {
        $empty = $this->history([]);

        foreach (app(AchievementRegistry::class)->all() as $rule) {
            $this->assertSame(0, $rule->progress($empty),
                "правило {$rule->code()} должно давать 0 на пустой истории");
        }
    }

    public function test_no_rule_exceeds_its_target(): void
    {
        // Заведомо избыточная история: ни одно правило не должно перевалить за цель.
        $matches = array_map(fn ($i) => $this->match([
            'sort_date' => $i,
            'tournament_id' => $i,
            'tournament_type' => 'type' . $i,
            'club_id' => $i,
        ]), range(1, 60));

        $history = $this->history($matches, collect(), ['total' => 100, 'wins' => 100]);

        foreach (app(AchievementRegistry::class)->all() as $rule) {
            $this->assertLessThanOrEqual($rule->target(), $rule->progress($history),
                "правило {$rule->code()} вылезло за цель");
        }
    }
}
