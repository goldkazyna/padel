<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FCMNotificationService
{
    protected Messaging $messaging;

    public function __construct(Messaging $messaging)
    {
        $this->messaging = $messaging;
    }

    /**
     * Отправить уведомление пользователю на все его устройства
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        $tokens = $user->deviceTokens()->pluck('token')->toArray();

        if (empty($tokens)) {
            Log::info('FCM: no device tokens for user', ['user_id' => $user->id]);
            return false;
        }

        $notification = Notification::create($title, $body);
        $message = CloudMessage::new()
            ->withNotification($notification)
            ->withData($data);

        try {
            $report = $this->messaging->sendMulticast($message, $tokens);

            if ($report->hasFailures()) {
                foreach ($report->failures()->getItems() as $failure) {
                    Log::warning('FCM: failed to send', [
                        'user_id' => $user->id,
                        'token' => $failure->target()->value(),
                        'error' => $failure->error()->getMessage(),
                    ]);

                    // Удаляем невалидные токены
                    if ($this->isInvalidTokenError($failure->error()->getMessage())) {
                        $user->deviceTokens()->where('token', $failure->target()->value())->delete();
                    }
                }
            }

            return $report->successes()->count() > 0;
        } catch (\Exception $e) {
            Log::error('FCM: send error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Отправить уведомление в топик
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): bool
    {
        $notification = Notification::create($title, $body);
        $message = CloudMessage::withTarget('topic', $topic)
            ->withNotification($notification)
            ->withData($data);

        try {
            $this->messaging->send($message);
            return true;
        } catch (\Exception $e) {
            Log::error('FCM: topic send error', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Проверяем, является ли ошибка невалидным токеном
     */
    protected function isInvalidTokenError(string $error): bool
    {
        return str_contains($error, 'not-found')
            || str_contains($error, 'invalid-registration-token')
            || str_contains($error, 'registration-token-not-registered');
    }
}
