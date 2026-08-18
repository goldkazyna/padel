<?php

namespace App\Console\Commands;

use App\Models\AmericanoFlexPlayer;
use App\Models\AmericanoFlexRound;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AmericanoFlexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Прогон расписания Americano Flex: сколько раундов нужно, чтобы все
 * сыграли поровну.
 *
 * Ничего не сохраняет: всё создаётся в транзакции и откатывается.
 *
 *   php artisan flex:simulate --courts=2 --players=9,10,11,12 --rounds=14
 */
class SimulateFlex extends Command
{
    protected $signature = 'flex:simulate {--courts=2} {--players=9,10,11,12} {--rounds=14}';
    protected $description = 'Прогнать расписание Americano Flex и показать, когда отдых выравнивается';

    public function handle(AmericanoFlexService $flex): int
    {
        $courts = (int) $this->option('courts');
        $maxRounds = (int) $this->option('rounds');

        foreach (explode(',', (string) $this->option('players')) as $raw) {
            $count = (int) trim($raw);
            if ($count <= 0) {
                continue;
            }
            $this->simulate($flex, $courts, $count, $maxRounds);
        }

        return self::SUCCESS;
    }

    private function simulate(AmericanoFlexService $flex, int $courts, int $players, int $maxRounds): void
    {
        DB::beginTransaction();

        try {
            $club = Club::create(['name' => 'Прогон', 'address' => '—']);
            $tournament = Tournament::create([
                'club_id' => $club->id,
                'name' => "Прогон {$players}",
                'type' => 'americano_flex',
                'status' => 'open',
                'start_date' => now(),
                'courts_count' => $courts,
                'max_participants' => $players,
                'min_level' => 1,
                'max_level' => 5,
                'created_by' => User::first()?->id ?? 1,
            ]);

            for ($i = 0; $i < $players; $i++) {
                $user = User::create([
                    'first_name' => 'Игрок',
                    'last_name' => (string) ($i + 1),
                    'name' => 'Игрок ' . ($i + 1),
                    'phone' => '7999' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
                    'password' => bcrypt('x'),
                    'rating' => 1000 + $i * 50,
                    'role' => 'player',
                ]);
                $tournament->participants()->attach($user->id, ['status' => 'registered']);
            }

            if (!$flex->startTournament($tournament)) {
                $this->error("{$players} игроков на {$courts} корта: старт не прошёл");

                return;
            }

            $this->line('');
            $this->info("=== {$players} игроков, {$courts} корта ===");

            $equalAt = null;
            for ($round = 1; $round <= $maxRounds; $round++) {
                if ($round > 1) {
                    $this->completeRound($tournament, $flex);
                    if (!$flex->canGenerateNextRound($tournament)) {
                        $this->warn("  раунд {$round}: генератор дальше не идёт");
                        break;
                    }
                    $flex->generateNextRound($tournament);
                }

                $byes = AmericanoFlexPlayer::where('tournament_id', $tournament->id)
                    ->pluck('bye_count')->all();
                sort($byes);
                $spread = max($byes) - min($byes);

                if ($spread === 0 && $equalAt === null && $round > 1) {
                    $equalAt = $round;
                }

                $this->line(sprintf(
                    '  после раунда %-2d отдыхали: %s%s',
                    $round,
                    implode(' ', $byes),
                    $spread === 0 ? '   ← у всех поровну' : ''
                ));
            }

            $resting = $players - $courts * 4;
            $this->line("  каждый раунд отдыхает: {$resting}");
        } finally {
            DB::rollBack();
        }
    }

    /** Проставляем любые счета, чтобы раунд считался сыгранным. */
    private function completeRound(Tournament $tournament, AmericanoFlexService $flex): void
    {
        $round = $flex->getCurrentRound($tournament);
        if (!$round) {
            return;
        }
        foreach ($round->matches as $match) {
            if ($match->status !== 'completed') {
                $flex->saveMatchResult($match, 16, 8);
            }
        }
    }
}
