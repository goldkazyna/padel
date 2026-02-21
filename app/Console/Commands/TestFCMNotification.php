<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class TestFCMNotification extends Command
{
    protected $signature = 'fcm:test {phone : Телефон пользователя}';
    protected $description = 'Отправить тестовое push-уведомление пользователю';

    public function handle(Messaging $messaging): int
    {
        $user = User::where('phone', $this->argument('phone'))->first();

        if (!$user) {
            $this->error('Пользователь не найден');
            return 1;
        }

        $tokens = $user->deviceTokens;

        if ($tokens->isEmpty()) {
            $this->error("У пользователя {$user->full_name} нет зарегистрированных устройств");
            return 1;
        }

        $this->info("Найдено устройств: {$tokens->count()}");
        foreach ($tokens as $token) {
            $this->line("  - {$token->platform}: " . substr($token->token, 0, 20) . '...');
        }

        $notification = Notification::create('Тестовое уведомление', 'Push работает!');
        $apns = ApnsConfig::fromArray([
            'payload' => [
                'aps' => [
                    'alert' => [
                        'title' => 'Тестовое уведомление',
                        'body' => 'Push работает!',
                    ],
                    'sound' => 'default',
                    'badge' => 1,
                    'content-available' => 1,
                ],
            ],
        ]);
        $message = CloudMessage::new()
            ->withNotification($notification)
            ->withApnsConfig($apns)
            ->withData(['type' => 'test']);

        $tokenValues = $tokens->pluck('token')->toArray();

        try {
            $report = $messaging->sendMulticast($message, $tokenValues);

            $this->info("Успешно: {$report->successes()->count()}");
            $this->info("Ошибок: {$report->failures()->count()}");

            if ($report->hasFailures()) {
                foreach ($report->failures()->getItems() as $failure) {
                    $failedToken = $failure->target()->value();
                    $platform = $tokens->firstWhere('token', $failedToken)?->platform ?? '?';
                    $this->error("  FAIL [{$platform}]: {$failure->error()->getMessage()}");
                }
            }

            foreach ($report->successes()->getItems() as $success) {
                $sentToken = $success->target()->value();
                $platform = $tokens->firstWhere('token', $sentToken)?->platform ?? '?';
                $this->info("  OK [{$platform}]");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("Exception: {$e->getMessage()}");
            return 1;
        }
    }
}
