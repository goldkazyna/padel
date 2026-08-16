<?php

namespace App\Services;

use App\Achievements\AchievementRegistry;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserAchievement;

/**
 * Уведомление о новых значках.
 *
 * Пуш один на пачку: за один турнир может открыться сразу пять значков,
 * и пять уведомлений подряд читаются как спам.
 *
 * Это единственное место, откуда уходит пуш про достижения. Заливка истории
 * про этот класс не знает — специально, чтобы разовый прогон по всей базе
 * физически не мог разослать уведомления.
 */
class AchievementNotifier
{
    public function __construct(private readonly AchievementRegistry $registry)
    {
    }

    /** @param array<int, string> $codes коды значков, полученных только что */
    public function notify(User $user, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        // Пока уведомления выключены, значки всё равно помечаем отправленными.
        // Иначе в день включения на игроков разом высыпался бы весь накопленный
        // за тихий период запас — ровно то, от чего спасает заливка истории.
        if (!config('mobile_app.achievements_push', false)) {
            $this->markNotified($user, $codes);

            return;
        }

        [$title, $body] = $this->text($codes);

        // Запись создаём до отправки: значок должен быть виден в приложении,
        // даже если push не уйдёт.
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => 'achievement',
            'category' => 'achievement',
            'data' => ['achievement_code' => $codes[0]],
        ]);

        try {
            app(FCMNotificationService::class)->sendToUser($user, $title, $body, [
                'type' => 'achievement',
                'achievement_code' => (string) $codes[0],
            ]);
        } catch (\Throwable $e) {
            // Push не критичен: значок уже виден в приложении.
        }

        // Отметку ставим независимо от исхода отправки. Повторять попытку
        // нельзя: чаще всего «не ушёл» значит «приложение не установлено»,
        // и крон плодил бы уведомления каждые десять минут.
        $this->markNotified($user, $codes);
    }

    /** @param array<int, string> $codes */
    private function markNotified(User $user, array $codes): void
    {
        UserAchievement::where('user_id', $user->id)
            ->whereIn('code', $codes)
            ->update(['notified_at' => now()]);
    }

    /**
     * @param array<int, string> $codes
     * @return array{0: string, 1: string}
     */
    private function text(array $codes): array
    {
        if (count($codes) === 1) {
            $rule = $this->registry->byCode($codes[0]);

            return ['Новое достижение', $rule?->title() ?? 'Открыт новый значок'];
        }

        return ['Новые достижения', 'Открыто значков: ' . count($codes)];
    }
}
