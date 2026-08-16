<?php

namespace App\Services;

use App\Achievements\Achievement;
use App\Achievements\AchievementRegistry;
use App\Achievements\PlayerHistory;
use App\Models\User;
use App\Models\UserAchievement;

/**
 * Пересчёт значков игрока и подготовка их к показу.
 *
 * Уведомления этот сервис не шлёт: рассылка живёт в AchievementNotifier,
 * иначе открытие экрана порождало бы пуш о том, что игрок и так видит.
 */
class AchievementService
{
    public function __construct(private readonly AchievementRegistry $registry)
    {
    }

    /**
     * Пересчитать значки игрока.
     *
     * @return array<int, string> коды значков, полученных именно этим вызовом
     */
    public function sync(User $user): array
    {
        $history = PlayerHistory::for($user);
        $rows = UserAchievement::where('user_id', $user->id)->get()->keyBy('code');
        $fresh = [];

        foreach ($this->registry->all() as $rule) {
            $progress = $rule->progress($history);
            $row = $rows->get($rule->code());
            $wasUnlocked = $row?->unlocked_at !== null;

            // Прогресс не откатываем: правку счёта задним числом игрок не должен
            // воспринимать как отобранную награду.
            $progress = max($progress, (int) ($row->progress ?? 0));
            $isUnlocked = $progress >= $rule->target();

            UserAchievement::updateOrCreate(
                ['user_id' => $user->id, 'code' => $rule->code()],
                [
                    'progress' => $progress,
                    'target' => $rule->target(),
                    'unlocked_at' => $wasUnlocked ? $row->unlocked_at : ($isUnlocked ? now() : null),
                ]
            );

            if ($isUnlocked && !$wasUnlocked) {
                $fresh[] = $rule->code();
            }
        }

        return $fresh;
    }

    /**
     * Все значки игрока с прогрессом — для своего профиля.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forOwner(User $user): array
    {
        $rows = UserAchievement::where('user_id', $user->id)->get()->keyBy('code');

        return array_map(
            fn (Achievement $rule) => $this->present($rule, $rows->get($rule->code())),
            $this->registry->all()
        );
    }

    /**
     * Только полученные — для чужой карточки. Без пересчёта: чужие профили
     * открывают часто, а значки обновит либо крон, либо сам владелец.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forVisitor(User $user): array
    {
        $rows = UserAchievement::where('user_id', $user->id)
            ->whereNotNull('unlocked_at')
            ->get()
            ->keyBy('code');

        $result = [];
        foreach ($this->registry->all() as $rule) {
            $row = $rows->get($rule->code());
            if ($row) {
                $result[] = $this->present($rule, $row);
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function present(Achievement $rule, ?UserAchievement $row): array
    {
        return [
            'code' => $rule->code(),
            'title' => $rule->title(),
            'description' => $rule->description(),
            'icon' => $rule->icon(),
            'group' => $rule->group(),
            'progress' => (int) ($row->progress ?? 0),
            'target' => $rule->target(),
            'unlocked_at' => $row?->unlocked_at?->toIso8601String(),
        ];
    }
}
