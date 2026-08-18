<?php

namespace App\Console\Commands;

use App\Achievements\AchievementRegistry;
use App\Achievements\PlayerHistory;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Console\Command;

/**
 * Пересчёт значков БЕЗ защиты от отката.
 *
 * Обычный `achievements:sync` прогресс не понижает: правку счёта задним
 * числом игрок не должен воспринимать как отобранную награду. Но когда
 * ошибка была в самом правиле, выданное по ошибке так и висит навсегда —
 * для таких случаев эта команда.
 *
 *   php artisan achievements:recheck jump_100 level_up          # показать
 *   php artisan achievements:recheck jump_100 level_up --apply  # снять
 */
class RecheckAchievements extends Command
{
    protected $signature = 'achievements:recheck {codes* : коды значков} {--apply}';
    protected $description = 'Пересчитать значки заново и снять выданные по ошибке (показать/применить)';

    public function handle(AchievementRegistry $registry): int
    {
        $codes = $this->argument('codes');
        $rules = collect($registry->all())->keyBy(fn ($rule) => $rule->code());

        foreach ($codes as $code) {
            if (!$rules->has($code)) {
                $this->error("Значка «{$code}» не существует");

                return self::FAILURE;
            }
        }

        // Смотрим только тех, у кого эти значки есть: остальных пересчёт
        // всё равно не изменит, а игроков в базе тысячи.
        $rows = UserAchievement::whereIn('code', $codes)->get();
        $this->info("Записей к проверке: {$rows->count()}");

        $revoke = [];
        foreach ($rows->groupBy('user_id') as $userId => $userRows) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            $history = PlayerHistory::for($user);
            foreach ($userRows as $row) {
                $rule = $rules->get($row->code);
                $fresh = $rule->progress($history);
                $wasUnlocked = $row->unlocked_at !== null;
                $nowUnlocked = $fresh >= $rule->target();

                if ($fresh < (int) $row->progress || ($wasUnlocked && !$nowUnlocked)) {
                    $revoke[] = [
                        'row' => $row,
                        'name' => $user->name,
                        'code' => $row->code,
                        'was' => (int) $row->progress,
                        'now' => $fresh,
                        'unlocked' => $wasUnlocked,
                    ];
                }
            }
        }

        if (empty($revoke)) {
            $this->info('Снимать нечего — все значки заслужены.');

            return self::SUCCESS;
        }

        $this->table(
            ['Игрок', 'Значок', 'Прогресс был', 'Стал', 'Был выдан'],
            array_map(fn ($r) => [
                $r['name'],
                $r['code'],
                $r['was'],
                $r['now'],
                $r['unlocked'] ? 'да' : 'нет',
            ], $revoke)
        );

        if (!$this->option('apply')) {
            $this->warn('Режим показа. Чтобы снять — добавь --apply');

            return self::SUCCESS;
        }

        foreach ($revoke as $r) {
            $rule = $rules->get($r['code']);
            $r['row']->update([
                'progress' => $r['now'],
                'unlocked_at' => $r['now'] >= $rule->target() ? $r['row']->unlocked_at : null,
            ]);
        }

        $this->info('Снято: ' . count($revoke));

        return self::SUCCESS;
    }
}
