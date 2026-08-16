<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use App\Models\User;
use App\Services\AchievementNotifier;
use App\Services\AchievementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Пересчитать значки тем, кто только что доиграл, и уведомить о новых.
 *
 * Берём участников недавно завершённых турниров, а не всех подряд: полный
 * проход по базе означал бы обход десяти таблиц форматов на каждого игрока.
 */
class SyncAchievements extends Command
{
    protected $signature = 'achievements:sync {--hours=1 : за сколько часов назад брать турниры}';

    protected $description = 'Пересчитать значки недавно игравших и отправить уведомления';

    public function handle(AchievementService $service, AchievementNotifier $notifier): int
    {
        $since = now()->subHours((int) $this->option('hours'));

        $userIds = Tournament::where('status', 'completed')
            ->where('updated_at', '>=', $since)
            ->with('participants:id')
            ->get()
            ->flatMap(fn ($t) => $t->participants->pluck('id'))
            ->unique()
            ->values();

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            try {
                $notifier->notify($user, $service->sync($user));
            } catch (\Throwable $e) {
                // Один сломанный профиль не должен ронять рассылку остальным.
                Log::error('achievements:sync упал на игроке', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Обработано игроков: {$userIds->count()}");

        return self::SUCCESS;
    }
}
