<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Models\AmericanoFlexPlayer;
use App\Services\AmericanoFlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FlexSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulate_flex_16_players_3_courts(): void
    {
        $players = 16;
        $courts = 3;

        $club = Club::create(['name' => 'Sim', 'address' => 'A', 'city' => 'Алматы']);
        $t = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano_flex',
            'status' => 'open',
            'max_participants' => $players,
            'courts_count' => $courts,
        ]);

        $label = []; // user_id => 'P01'
        for ($i = 1; $i <= $players; $i++) {
            $u = User::factory()->create([
                'name' => sprintf('P%02d', $i),
                'rating' => 1600 - $i * 20,
            ]);
            $label[$u->id] = sprintf('P%02d', $i);
            TournamentParticipant::create([
                'tournament_id' => $t->id,
                'user_id' => $u->id,
                'status' => 'registered',
            ]);
        }

        $svc = app(AmericanoFlexService::class);
        $this->assertTrue($svc->startTournament($t), 'старт не прошёл');

        $out = [];
        $out[] = "=== Americano Flex: {$players} игроков / {$courts} корта ===";

        $cur = [];      // label => текущая серия отдыха подряд
        $maxStreak = []; // label => макс серия отдыха подряд
        foreach ($label as $lb) { $cur[$lb] = 0; $maxStreak[$lb] = 0; }

        $maxRounds = 16;
        for ($r = 1; $r <= $maxRounds; $r++) {
            $round = $svc->getCurrentRound($t);
            if (!$round) break;

            $out[] = "";
            $out[] = "Раунд {$round->round_number}:";
            foreach ($round->matches()->orderBy('court_number')->get() as $m) {
                // Счёт: детерминированный, с победителем.
                $s1 = 4 + (($round->round_number + $m->court_number) % 4);
                $s2 = 4 + (($round->round_number + $m->court_number + 2) % 4);
                if ($s1 === $s2) $s1++;
                $svc->saveMatchResult($m, $s1, $s2);

                $t1 = $label[$m->team1_player1_id] . '+' . $label[$m->team1_player2_id];
                $t2 = $label[$m->team2_player1_id] . '+' . $label[$m->team2_player2_id];
                $out[] = sprintf('  Корт %d: %-9s vs %-9s  %d:%d',
                    $m->court_number, $t1, $t2, $s1, $s2);
            }
            $byes = $round->byes()->get()->map(fn ($b) => $label[$b->user_id])->sort()->values()->all();
            $out[] = '  Отдыхают: ' . (empty($byes) ? '—' : implode(', ', $byes));

            // Серии отдыха подряд (только по реально сыгранным раундам)
            $restingSet = array_flip($byes);
            foreach ($label as $lb) {
                if (isset($restingSet[$lb])) {
                    $cur[$lb]++;
                    $maxStreak[$lb] = max($maxStreak[$lb], $cur[$lb]);
                } else {
                    $cur[$lb] = 0;
                }
            }

            // Не генерим лишний раунд после последнего — иначе byes посчитаются с запасом.
            if ($r >= $maxRounds || !$svc->canGenerateNextRound($t)) break;
            $svc->generateNextRound($t);
        }

        // Итог по игрокам
        $out[] = "";
        $out[] = "=== ИТОГ (сыграно / отдыхал / очки) ===";
        $rows = AmericanoFlexPlayer::where('tournament_id', $t->id)->get()
            ->map(fn ($p) => [
                'label' => $label[$p->user_id],
                'played' => (int) $p->matches_played,
                'byes' => (int) $p->bye_count,
                'points' => (int) $p->total_points,
            ])
            ->sortBy('label')->values();

        foreach ($rows as $row) {
            $lb = $row['label'];
            $out[] = sprintf('  %-4s  сыграл %2d   отдыхал %2d   отдых подряд(макс) %d   очки %3d',
                $lb, $row['played'], $row['byes'], $maxStreak[$lb], $row['points']);
        }

        $playedVals = $rows->pluck('played')->all();
        $streakVals = array_values($maxStreak);
        $out[] = "";
        $out[] = 'Матчей сыграно (мин..макс): ' . min($playedVals) . '..' . max($playedVals)
            . '  | разброс: ' . (max($playedVals) - min($playedVals));
        $out[] = 'Макс отдыха подряд по всему турниру: ' . max($streakVals)
            . ' (у скольких игроков ' . max($streakVals) . ' подряд: '
            . count(array_filter($streakVals, fn ($v) => $v === max($streakVals))) . ')';

        fwrite(STDERR, "\n" . implode("\n", $out) . "\n");

        $this->assertGreaterThan(0, array_sum($playedVals));
    }
}
