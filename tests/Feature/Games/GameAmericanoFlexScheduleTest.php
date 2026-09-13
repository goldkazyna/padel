<?php

namespace Tests\Feature\Games;

use App\Support\AmericanoGameSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Игра-Американо играется по готовой сетке Флекс — той же, что турниры.
 *
 * Свой алгоритм давал по четыре матча каждому и повторял партнёров; в
 * таблицах расклад посчитан заранее: партнёр не повторяется, отдых
 * разложен поровну.
 */
class GameAmericanoFlexScheduleTest extends TestCase
{
    /** @return array<int,int> */
    private function players(int $n): array
    {
        return range(101, 100 + $n);
    }

    /**
     * Что обещает таблица на одном корте: игроков, раундов и сколько пар
     * в ней повторяется (у восьмерых иначе не разложить — 14 раундов,
     * 28 пар на 28 возможных сочетаний не садятся ровно).
     */
    public static function counts(): array
    {
        return [
            '4 игрока' => [4, 3, 0],
            '5 игроков' => [5, 5, 0],
            '6 игроков' => [6, 7, 0],
            '7 игроков' => [7, 10, 0],
            '8 игроков' => [8, 14, 4],
        ];
    }

    #[DataProvider('counts')]
    public function test_раундов_столько_же_сколько_в_таблице(int $n, int $rounds, int $repeats): void
    {
        $this->assertCount($rounds, AmericanoGameSchedule::build($this->players($n)));
    }

    #[DataProvider('counts')]
    public function test_в_раунде_четверо_разных_игроков(int $n, int $rounds, int $repeats): void
    {
        foreach (AmericanoGameSchedule::build($this->players($n)) as $i => $round) {
            $ids = array_merge($round['a'], $round['b']);
            $this->assertCount(4, $ids, "раунд $i");
            $this->assertCount(4, array_unique($ids), "раунд $i: игрок дважды на корте");
            foreach ($ids as $id) {
                $this->assertContains($id, $this->players($n));
            }
        }
    }

    #[DataProvider('counts')]
    public function test_партнёры_повторяются_не_чаще_обещанного(int $n, int $rounds, int $repeats): void
    {
        $seen = [];
        foreach (AmericanoGameSchedule::build($this->players($n)) as $round) {
            foreach ([$round['a'], $round['b']] as $pair) {
                sort($pair);
                $key = implode('-', $pair);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }

        $repeated = array_sum(array_map(fn ($c) => $c - 1, array_filter($seen, fn ($c) => $c > 1)));
        $this->assertSame($repeats, $repeated,
            'повторов пар больше, чем обещает таблица: ' . json_encode(array_filter($seen, fn ($c) => $c > 1)));
    }

    #[DataProvider('counts')]
    public function test_матчи_разложены_поровну(int $n, int $rounds, int $repeats): void
    {
        $played = array_fill_keys($this->players($n), 0);
        foreach (AmericanoGameSchedule::build($this->players($n)) as $round) {
            foreach (array_merge($round['a'], $round['b']) as $id) {
                $played[$id]++;
            }
        }

        $this->assertLessThanOrEqual(1, max($played) - min($played),
            'разрыв по числу матчей больше одного: ' . json_encode($played));
    }

    public function test_меньше_четверых_играть_нечем(): void
    {
        $this->assertSame([], AmericanoGameSchedule::build($this->players(3)));
    }

    public function test_без_таблицы_работает_алгоритм(): void
    {
        // На 11 игроков готовой сетки нет — расписание всё равно строится.
        $rounds = AmericanoGameSchedule::build($this->players(11));

        $this->assertNotEmpty($rounds);
        foreach ($rounds as $round) {
            $this->assertCount(4, array_unique(array_merge($round['a'], $round['b'])));
        }
    }
}
