<?php

namespace App\Console\Commands;

use App\Achievements\AchievementRegistry;
use App\Achievements\PlayerHistory;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Пересчитать один значок честно, с правом опустить прогресс.
 *
 * Обычный achievements:sync прогресс не откатывает: правку счёта задним
 * числом игрок воспринимает как отобранную награду. Но когда меняется само
 * правило — например, из «Знатока форматов» убрали Round Robin, — сохранённые
 * числа остаются от старого правила и врут: «7 из 7», хотя формата семь и
 * один из них не сыгран.
 *
 * Уже выданный значок команда не отбирает: unlocked_at и прогресс не ниже
 * цели остаются. Меняются только незакрытые.
 */
class RecountAchievement extends Command
{
    protected $signature = 'achievements:recount {code : код значка, например formats_all} {--chunk=200}';

    protected $description = 'Пересчитать один значок по текущему правилу, включая уменьшение прогресса';

    public function handle(AchievementRegistry $registry): int
    {
        $code = $this->argument('code');
        $rule = collect($registry->all())->firstWhere(fn ($r) => $r->code() === $code);

        if (!$rule) {
            $this->error("Значка «{$code}» нет в реестре.");

            return self::FAILURE;
        }

        $target = $rule->target();
        $changed = 0;
        $seen = 0;

        UserAchievement::where('code', $code)
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($rows) use ($rule, $target, &$changed, &$seen) {
                foreach ($rows as $row) {
                    $seen++;
                    $user = User::find($row->user_id);
                    if (!$user) {
                        continue;
                    }

                    try {
                        $progress = $rule->progress(PlayerHistory::for($user));
                    } catch (\Throwable $e) {
                        Log::error('achievements:recount упал на игроке', [
                            'user_id' => $row->user_id,
                            'code' => $rule->code(),
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    // Выданное не отбираем: значок остаётся, а прогресс под ним
                    // не опускаем ниже цели, иначе получится «6 из 7» у
                    // открытого значка.
                    if ($row->unlocked_at !== null) {
                        $progress = max($progress, $target);
                    }

                    $unlockedAt = $row->unlocked_at ?? ($progress >= $target ? now() : null);

                    if ((int) $row->progress === $progress
                        && (int) $row->target === $target
                        && $row->unlocked_at == $unlockedAt) {
                        continue;
                    }

                    $row->update([
                        'progress' => $progress,
                        'target' => $target,
                        'unlocked_at' => $unlockedAt,
                    ]);
                    $changed++;
                }
            });

        $this->info("Значок {$code}: просмотрено {$seen}, обновлено {$changed}, цель {$target}.");

        return self::SUCCESS;
    }
}
