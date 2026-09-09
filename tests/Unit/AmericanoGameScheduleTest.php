<?php

namespace Tests\Unit;

use App\Support\AmericanoGameSchedule;
use PHPUnit\Framework\TestCase;

class AmericanoGameScheduleTest extends TestCase
{
    public function test_four_players_play_every_round_and_every_partner(): void
    {
        $rounds = AmericanoGameSchedule::build([10, 20, 30, 40]);

        $this->assertCount(3, $rounds);
        $partners = [];
        foreach ($rounds as $r) {
            foreach ([$r['a'], $r['b']] as $pair) {
                sort($pair);
                $partners[] = implode('-', $pair);
            }
        }
        $this->assertCount(6, array_unique($partners), 'каждый сыграл с каждым по разу');
    }

    /** @dataProvider sizes */
    public function test_everyone_gets_the_same_number_of_matches(int $n): void
    {
        $ids = range(1, $n);
        $rounds = AmericanoGameSchedule::build($ids);

        $played = array_fill_keys($ids, 0);
        foreach ($rounds as $r) {
            $four = array_merge($r['a'], $r['b']);
            $this->assertCount(4, array_unique($four), 'в раунде четыре разных игрока');
            foreach ($four as $id) {
                $played[$id]++;
            }
        }

        $this->assertSame([4], array_values(array_unique($played)), 'по четыре матча каждому');
    }

    public static function sizes(): array
    {
        return [[5], [6], [7], [8], [10], [12], [16]];
    }

    public function test_partners_repeat_as_rarely_as_possible(): void
    {
        $rounds = AmericanoGameSchedule::build(range(1, 8));

        $partners = [];
        foreach ($rounds as $r) {
            foreach ([$r['a'], $r['b']] as $pair) {
                sort($pair);
                $k = implode('-', $pair);
                $partners[$k] = ($partners[$k] ?? 0) + 1;
            }
        }

        $this->assertSame(1, max($partners), 'на восьмерых пары не повторяются');
    }

    public function test_too_few_players_get_no_schedule(): void
    {
        $this->assertSame([], AmericanoGameSchedule::build([1, 2, 3]));
    }
}
