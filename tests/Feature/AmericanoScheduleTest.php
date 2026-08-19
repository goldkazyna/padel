<?php

namespace Tests\Feature;

use App\Services\AmericanoService;
use Tests\TestCase;

/**
 * Расписания Американо: как часто люди попадают друг к другу.
 *
 * Напарник не должен повторяться вовсе, соперник — не больше двух раз.
 * На 16 игроках раньше работал запасной алгоритм и давал тройные встречи.
 */
class AmericanoScheduleTest extends TestCase
{
    /** @return array<int, array<int, int>> */
    private function schedule(int $players): array
    {
        $service = app(AmericanoService::class);
        $method = new \ReflectionMethod($service, 'getOptimalSchedule');
        $method->setAccessible(true);

        return $method->invoke($service, $players);
    }

    /**
     * @return array{partners: array<string, int>, opponents: array<string, int>}
     */
    private function tally(array $schedule, ?int $limitRounds = null): array
    {
        $partners = [];
        $opponents = [];
        $round = 0;

        foreach ($schedule as $courts) {
            if ($limitRounds !== null && ++$round > $limitRounds) {
                break;
            }

            $seen = [];
            foreach ($courts as [$team1, $team2]) {
                foreach ([$team1, $team2] as $team) {
                    sort($team);
                    $key = implode('-', $team);
                    $partners[$key] = ($partners[$key] ?? 0) + 1;

                    foreach ($team as $player) {
                        $this->assertNotContains($player, $seen, 'игрок дважды в одном раунде');
                        $seen[] = $player;
                    }
                }
                foreach ($team1 as $a) {
                    foreach ($team2 as $b) {
                        $pair = [$a, $b];
                        sort($pair);
                        $key = implode('-', $pair);
                        $opponents[$key] = ($opponents[$key] ?? 0) + 1;
                    }
                }
            }
        }

        return ['partners' => $partners, 'opponents' => $opponents];
    }

    /** @return array<int, array<int, int>> */
    public static function sizes(): array
    {
        return ['8 игроков' => [8], '12 игроков' => [12], '16 игроков' => [16]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sizes')]
    public function test_partners_never_repeat(int $players): void
    {
        $schedule = $this->schedule($players);
        $this->assertNotNull($schedule, "для {$players} игроков должно быть расписание");

        $counts = $this->tally($schedule);

        $this->assertSame(1, max($counts['partners']), 'напарник не должен повторяться');
        $this->assertCount(
            $players * ($players - 1) / 2,
            $counts['partners'],
            'каждый должен сыграть в паре с каждым'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sizes')]
    public function test_opponents_meet_at_most_twice(int $players): void
    {
        $counts = $this->tally($this->schedule($players));

        $this->assertLessThanOrEqual(
            2,
            max($counts['opponents']),
            'против одного соперника — не больше двух раз'
        );
    }

    /** Турнир часто заканчивают раньше — свойство должно держаться и там. */
    public function test_property_holds_when_stopping_early(): void
    {
        $schedule = $this->schedule(16);

        foreach ([5, 7, 9, 11, 13] as $rounds) {
            $counts = $this->tally($schedule, $rounds);

            $this->assertSame(1, max($counts['partners']), "на {$rounds} раундах напарник повторился");
            $this->assertLessThanOrEqual(2, max($counts['opponents']), "на {$rounds} раундах соперник трижды");
        }
    }

    public function test_everyone_plays_every_round(): void
    {
        foreach ([8, 12, 16] as $players) {
            foreach ($this->schedule($players) as $roundNumber => $courts) {
                $onCourt = [];
                foreach ($courts as [$team1, $team2]) {
                    $onCourt = array_merge($onCourt, $team1, $team2);
                }
                $this->assertCount($players, array_unique($onCourt), "раунд {$roundNumber}: играют не все");
            }
        }
    }
}
