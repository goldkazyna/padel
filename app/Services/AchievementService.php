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
    /** Меньше этого числа играющих — доли не показываем, они ничего не значат. */
    private const RARITY_MIN_PLAYERS = 20;

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
        $rarity = $this->rarity();

        return array_map(
            fn (Achievement $rule) => $this->present($rule, $rows->get($rule->code()), $rarity),
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

        $rarity = $this->rarity();
        $result = [];
        foreach ($this->registry->all() as $rule) {
            $row = $rows->get($rule->code());
            if ($row) {
                $result[] = $this->present($rule, $row, $rarity);
            }
        }

        return $result;
    }

    /**
     * @param array<string, int> $rarity доля игроков по коду значка
     * @return array<string, mixed>
     */
    private function present(Achievement $rule, ?UserAchievement $row, array $rarity = []): array
    {
        return [
            'code' => $rule->code(),
            'title' => $rule->title(),
            'description' => $rule->description(),
            'icon' => $rule->icon(),
            'group' => $rule->group(),
            'tier' => $rule->tier(),
            'progress' => (int) ($row->progress ?? 0),
            'target' => $rule->target(),
            'unlocked_at' => $row?->unlocked_at?->toIso8601String(),
            'rarity' => $rarity[$rule->code()] ?? null,
        ];
    }

    /**
     * Сколько процентов игроков открыли каждый значок.
     *
     * Именно эта цифра превращает значок в награду: «есть у 9%» весит больше
     * любой картинки. За сто процентов берём тех, кто вообще играл, — у них
     * открыт «Дебют». Считать от всех зарегистрированных нечестно: половина
     * базы не сыграла ни одного турнира, и любая медаль выглядела бы редкой.
     *
     * Пока играющих мало, доли не показываем вовсе: «есть у 50%» при четырёх
     * игроках — не редкость, а шум.
     *
     * @return array<string, int>
     */
    private function rarity(): array
    {
        $counts = UserAchievement::whereNotNull('unlocked_at')
            ->selectRaw('code, count(*) as total')
            ->groupBy('code')
            ->pluck('total', 'code');

        $base = (int) ($counts['debut'] ?? 0);
        if ($base < self::RARITY_MIN_PLAYERS) {
            return [];
        }

        return $counts
            ->map(fn ($count) => (int) round($count / $base * 100))
            ->all();
    }
}
