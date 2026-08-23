<?php

namespace Tests\Unit;

use App\Support\AmericanoFlexRanking;
use Tests\TestCase;

/**
 * Порядок критериев в таблице Americano Flex:
 * среднее → % побед → личная встреча → рейтинг → id.
 *
 * Проверяем сам компаратор на собранных строках: в живом турнире редко
 * удаётся выстроить совпадение сразу по нескольким критериям.
 */
class AmericanoFlexRankingTest extends TestCase
{
    /** Строка таблицы: id, забито, матчей, побед, рейтинг. */
    private function row(int $id, int $pointsFor, int $matches, int $wins, int $rating = 1500): array
    {
        return [
            'id' => $id,
            'points_for' => $pointsFor,
            'matches' => $matches,
            'wins' => $wins,
            'rating' => $rating,
        ];
    }

    private function order(array $rows, array $h2h = []): array
    {
        return array_map(fn($r) => $r['id'], AmericanoFlexRanking::sortRows($rows, $h2h));
    }

    public function test_среднее_решает_первым(): void
    {
        // У игрока 2 меньше матчей и меньше очков, но среднее выше.
        $rows = [
            $this->row(1, 60, 3, 3), // 20.00
            $this->row(2, 21, 1, 1), // 21.00
        ];

        $this->assertSame([2, 1], $this->order($rows));
    }

    public function test_при_равном_среднем_решает_процент_побед(): void
    {
        // Оба по 20.00, но игрок 2 выиграл все свои матчи.
        $rows = [
            $this->row(1, 60, 3, 2), // 67%
            $this->row(2, 40, 2, 2), // 100%
        ];

        $this->assertSame([2, 1], $this->order($rows));
    }

    public function test_большее_число_матчей_само_по_себе_не_поднимает(): void
    {
        // Всё одинаково, кроме числа матчей: раньше выше вставал тот, у кого
        // больше сумма очков, то есть просто тот, кому меньше досталось отдыхов.
        $rows = [
            $this->row(1, 60, 3, 3, 1500),
            $this->row(2, 40, 2, 2, 1600),
        ];

        // Среднее 20.00 и 100% побед у обоих → решает рейтинг.
        $this->assertSame([2, 1], $this->order($rows));
    }

    public function test_при_равном_проценте_решает_личная_встреча(): void
    {
        $rows = [
            $this->row(1, 40, 2, 1, 1700),
            $this->row(2, 40, 2, 1, 1500),
        ];
        // Игрок 2 обыграл игрока 1 в очной встрече — он выше, несмотря на рейтинг.
        $h2h = [2 => [1 => 1], 1 => [2 => -1]];

        $this->assertSame([2, 1], $this->order($rows, $h2h));
    }

    public function test_без_личной_встречи_решает_рейтинг(): void
    {
        $rows = [
            $this->row(1, 40, 2, 1, 1500),
            $this->row(2, 40, 2, 1, 1900),
        ];

        $this->assertSame([2, 1], $this->order($rows));
    }

    public function test_при_полном_равенстве_порядок_определён_и_не_зависит_от_входа(): void
    {
        $a = $this->row(7, 40, 2, 1, 1500);
        $b = $this->row(3, 40, 2, 1, 1500);

        // Один и тот же ответ независимо от того, как строки пришли из базы.
        $this->assertSame([3, 7], $this->order([$a, $b]));
        $this->assertSame([3, 7], $this->order([$b, $a]));
    }

    public function test_игрок_без_матчей_уходит_вниз(): void
    {
        $rows = [
            $this->row(1, 0, 0, 0),
            $this->row(2, 5, 1, 0),
        ];

        $this->assertSame([2, 1], $this->order($rows));
    }

    public function test_места_нумеруются_подряд_с_единицы(): void
    {
        $rows = [$this->row(1, 40, 2, 2), $this->row(2, 30, 2, 1), $this->row(3, 20, 2, 0)];

        $this->assertSame([1, 2, 3], array_column(AmericanoFlexRanking::sortRows($rows, []), 'position'));
    }

    public function test_среднее_сравнивается_точно_а_не_по_двум_знакам(): void
    {
        // 50/3 = 16.6667 против 33/2 = 16.50 — округление до сотых их не слепит,
        // но проверяем, что сравнение идёт дробями, а не round().
        $rows = [
            $this->row(1, 33, 2, 1),
            $this->row(2, 50, 3, 1),
        ];

        $this->assertSame([2, 1], $this->order($rows));
    }
}
