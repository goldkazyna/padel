<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserAchievement;
use App\Services\AchievementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Разовая заливка истории при выпуске достижений.
 *
 * На релизе у каждого игрока разом открывается вся его история. Если оставить
 * это крону, человек получит пуш за то, что заработал полгода назад. Здесь
 * значки проставляются молча: уже полученным сразу ставится notified_at.
 *
 * Команда не умеет слать уведомления — она не знает про AchievementNotifier.
 * Это не забывчивость, а защита: FCMNotificationService::sendToUser() не
 * фильтруется по PUSH_TEST_PHONES, и ошибка означала бы веерную рассылку
 * на всю базу без возможности остановить.
 */
class BackfillAchievements extends Command
{
    protected $signature = 'achievements:backfill {--chunk=200 : размер пачки игроков}';

    protected $description = 'Залить значки по истории, ничего не отправляя';

    public function handle(AchievementService $service): int
    {
        $processed = 0;

        User::query()->orderBy('id')->chunkById(
            (int) $this->option('chunk'),
            function ($users) use ($service, &$processed) {
                foreach ($users as $user) {
                    try {
                        $service->sync($user);

                        // Всё, что открылось по истории, считаем уже сообщённым.
                        UserAchievement::where('user_id', $user->id)
                            ->whereNotNull('unlocked_at')
                            ->whereNull('notified_at')
                            ->update(['notified_at' => now()]);
                    } catch (\Throwable $e) {
                        Log::error('achievements:backfill упал на игроке', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $processed++;
                }

                $this->info("Обработано: {$processed}");
            }
        );

        $this->info("Готово. Игроков: {$processed}");

        return self::SUCCESS;
    }
}
