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
        $alreadyHadPlayoff = $t->playoffMatches()->exists();

        DB::beginTransaction();
        try {
            // 1) Внести плей-офф (идемпотентно — только если ещё нет)
            if ($alreadyHadPlayoff) {
                $this->warn('Плей-офф уже внесён ранее — повторно не вношу и рейтинг НЕ начисляю.');
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

            // 2) Чистый вклад плей-офф = (пересчёт с плей-офф) − (пересчёт только группа).
            //    Группа сокращается точно, старую точку #809 не трогаем.
            $groupOnly = $mexicano->recomputeRatingDeltas($t, false);
            $full = $mexicano->recomputeRatingDeltas($t, true);
            $players = $t->mexicanoPlayers()->with('user')->get();

            $rows = [];
            $corrections = [];
            foreach ($players as $p) {
                $u = $p->user;
                if (!$u) continue;
                $uid = $p->user_id;
                $playoffDelta = (int) ($full[$uid] ?? 0) - (int) ($groupOnly[$uid] ?? 0);
                if ($playoffDelta === 0) continue; // только участники плей-офф

                $before = (int) $u->rating;
                $after = max(1, $before + $playoffDelta);
                $rows[] = [
                    $u->name,
                    ($playoffDelta >= 0 ? '+' : '') . $playoffDelta,
                    $before . ' → ' . $after,
                ];
                $corrections[$uid] = ['delta' => $playoffDelta, 'before' => $before, 'after' => $after];
            }

            $this->info("Турнир #{$t->id}: {$t->name}");
            $this->line('Вклад плей-офф → отдельная запись «Ручная корректировка» сегодня:');
            $this->table(['Игрок', 'Плей-офф Δ', 'Рейтинг'], $rows);

            if (!$apply) {
                DB::rollBack();
                $this->warn('Режим показа — ничего не сохранено. Для применения: --apply');
                return self::SUCCESS;
            }
            if ($alreadyHadPlayoff) {
                DB::rollBack();
                $this->warn('Уже было внесено ранее — рейтинг повторно не начисляю (защита от дублей).');
                return self::SUCCESS;
            }

            // 3) Начислить вклад плей-офф отдельной записью сегодняшним числом
            foreach ($corrections as $uid => $c) {
                RatingHistory::create([
                    'user_id' => $uid,
                    'tournament_id' => null,
                    'rating_before' => $c['before'],
                    'rating_after' => $c['after'],
                    'change' => $c['delta'],
                    'reason' => 'Ручная корректировка',
                ]);
                $level = max(1.0, min(5.75, floor($c['after'] / 250) * 0.25));
                User::where('id', $uid)->update(['rating' => $c['after'], 'level' => $level]);
            }

            DB::commit();
            $this->info('Применено ✓ (плей-офф внесён, вклад начислен ручной корректировкой)');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Ошибка: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
