<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Console\Command;

class TestFCMNotification extends Command
{
    protected $signature = 'fcm:test {phone : Телефон пользователя}';
    protected $description = 'Отправить тестовое push-уведомление пользователю';

    public function handle(FCMNotificationService $fcm): int
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
            $this->line("  - {$token->platform} (создан {$token->created_at})");
        }

        $result = $fcm->sendToUser($user, 'Тестовое уведомление', 'Если вы видите это — push работает!', [
            'type' => 'test',
        ]);

        if ($result) {
            $this->info('Уведомление отправлено!');
            return 0;
        }

        $this->error('Ошибка отправки. Проверь storage/logs/laravel.log');
        return 1;
    }
}
