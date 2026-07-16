<?php

namespace App\Console\Commands;

use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\TournamentPlayoffMatch;
use App\Models\User;
use App\Services\MexicanoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая операция для турнира #809 (Mexicano): внести офлайн-плей-офф
 * (2 полуфинала + финал + бронза) и пересчитать рейтинг с его учётом.
 *
 *   php artisan tournaments:add-playoff-809          # показать (ничего не меняет)
 *   php artisan tournaments:add-playoff-809 --apply  # применить
 */
class AddPlayoff809 extends Command
{
    protected $signature = 'tournaments:add-playoff-809 {--apply}';
    protected $description = 'Внести плей-офф турнира #809 и пересчитать рейтинг';

    // stage, match_number, is_bronze, [t1p1,t1p2,t2p1,t2p2], [s1,s2]
    private array $playoff = [
        ['Полуфинал', 1, false, [1774, 1664, 362, 1439], [17, 15]], // Ostap/Данияр vs Мерген/Алимжан
        ['Полуфинал', 2, false, [369, 1648, 1776, 898], [17, 15]],  // Ramazan/Обухов vs Тарас/Tair
        ['Финал',     1, false, [1774, 1664, 369, 1648], [22, 10]], // Ostap/Данияр vs Ramazan/Обухов
        ['Финал',     2, true,  [898, 1776, 362, 1439], [17, 15]],  // Бронза: Tair/Тарас vs Мерген/Алимжан
    ];

    public function handle(MexicanoService $mexicano): int
    {
        $t = Tournament::find(809);
        if (!$t || $t->type !== 'mexicano') {
            $this->error('Турнир 809 не найден или не mexicano');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        DB::beginTransaction();
        try {
            // 1) Внести плей-офф (идемпотентно — только если ещё нет)
            if ($t->playoffMatches()->exists()) {
                $this->warn('Плей-офф уже есть в системе — повторно не вношу, только пересчитываю.');
            } else {
                $t->update([
                    'has_playoff' => true,
                    'playoff_type' => 'semifinal_final',
                    'has_bronze_match' => true,
                ]);
                foreach ($this->playoff as [$stage, $num, $bronze, $ids, $sc]) {
                    TournamentPlayoffMatch::create([
                        'tournament_id' => $t->id,
                        'stage' => $stage,
                        'match_number' => $num,
                        'is_bronze' => $bronze,
                        'bracket' => 'upper',
                        'team1_player1_id' => $ids[0],
                        'team1_player2_id' => $ids[1],
                        'team2_player1_id' => $ids[2],
                        'team2_player2_id' => $ids[3],
                        'team1_score' => $sc[0],
                        'team2_score' => $sc[1],
                        'status' => 'completed',
                    ]);
                }
                $this->info('Плей-офф внесён: 2 полуфинала + финал + бронза.');
            }

            // 2) Пересчёт рейтинга (группа + плей-офф)
            $newDeltas = $mexicano->recomputeRatingDeltas($t);
            $players = $t->mexicanoPlayers()->with('user')->get();

            $rows = [];
            $toApply = [];
            foreach ($players as $p) {
                $u = $p->user;
                if (!$u) continue;
                $uid = $p->user_id;
                $history = RatingHistory::where('user_id', $uid)
                    ->where('tournament_id', $t->id)
                    ->orderByDesc('id')->first();
                $oldChange = $history ? (int) $history->change : 0;
                $newChange = (int) ($newDeltas[$uid] ?? 0);
                $adj = $newChange - $oldChange;
                $cur = (int) $u->rating;
                $new = max(1, $cur + $adj);

                if ($adj !== 0 || $history) {
                    $rows[] = [
                        $u->name,
                        $oldChange,
                        $newChange,
                        ($adj >= 0 ? '+' : '') . $adj,
                        $cur . ' → ' . $new,
                    ];
                }
                $toApply[$uid] = [
                    'adj' => $adj, 'new' => $new, 'newChange' => $newChange,
                    'history_id' => $history?->id,
                    'rating_before' => (int) ($history->rating_before ?? $cur),
                ];
            }

            $this->info("Турнир #{$t->id}: {$t->name}");
            $this->table(['Игрок', 'Старая Δ', 'Новая Δ (с плей-офф)', 'Коррекция', 'Рейтинг'], $rows);

            if (!$apply) {
                DB::rollBack();
                $this->warn('Режим показа — ничего не сохранено. Для применения: --apply');
                return self::SUCCESS;
            }

            // 3) Применить коррекцию рейтинга
            foreach ($toApply as $uid => $a) {
                if ($a['adj'] !== 0) {
                    User::where('id', $uid)->update(['rating' => $a['new']]);
                }
                if ($a['history_id']) {
                    RatingHistory::where('id', $a['history_id'])->update([
                        'change' => $a['newChange'],
                        'rating_after' => $a['rating_before'] + $a['newChange'],
                    ]);
                }
            }

            DB::commit();
            $this->info('Применено ✓ (плей-офф внесён, рейтинг пересчитан)');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Ошибка: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
