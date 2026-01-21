<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class SyncOldUsers extends Command
{
    protected $signature = 'sync:old-users {database : Путь к SQLite файлу}';
    
    protected $description = 'Синхронизация пользователей из старой SQLite базы';

    protected $stats = [
        'updated' => 0,
        'created' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    public function handle()
    {
        $dbPath = $this->argument('database');
        
        if (!file_exists($dbPath)) {
            $this->error("Файл базы данных не найден: {$dbPath}");
            return 1;
        }

        $this->info("🔄 Начинаем синхронизацию из: {$dbPath}");
        $this->newLine();

        // Подключаемся к старой базе
        config(['database.connections.old_sqlite' => [
            'driver' => 'sqlite',
            'database' => $dbPath,
        ]]);

        try {
            $oldUsers = DB::connection('old_sqlite')
                ->table('users')
                ->get();
        } catch (\Exception $e) {
            $this->error("Ошибка подключения к базе: " . $e->getMessage());
            return 1;
        }

        $this->info("📊 Найдено пользователей в старой базе: " . $oldUsers->count());
        $this->newLine();

        $bar = $this->output->createProgressBar($oldUsers->count());
        $bar->start();

        foreach ($oldUsers as $oldUser) {
            $this->processUser($oldUser);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Результаты
        $this->info("✅ Синхронизация завершена!");
        $this->table(
            ['Действие', 'Количество'],
            [
                ['Обновлено', $this->stats['updated']],
                ['Создано', $this->stats['created']],
                ['Пропущено', $this->stats['skipped']],
                ['Ошибок', $this->stats['errors']],
            ]
        );

        return 0;
    }

    protected function processUser($oldUser)
    {
        $telegramId = $oldUser->telegram_id ?? null;

        // Пропускаем резервных игроков (отрицательные telegram_id)
        if (!$telegramId || $telegramId < 0) {
            $this->stats['skipped']++;
            return;
        }

        // Нормализуем level (max 5.75)
        $level = $this->normalizeLevel($oldUser->player_level ?? 1.0);

        // Парсим имя
        $nameParts = $this->parseName($oldUser->full_name ?? '');

        // Нормализуем телефон
        $phone = $this->normalizePhone($oldUser->phone_number ?? null);

        try {
            // Ищем существующего пользователя
            $existingUser = User::where('telegram_id', $telegramId)->first();

            if ($existingUser) {
                // Обновляем только level
                $existingUser->update([
                    'level' => $level,
                ]);
                $this->stats['updated']++;
            } else {
                // Создаём нового пользователя
                User::create([
                    'telegram_id' => $telegramId,
                    'first_name' => $nameParts['first_name'],
                    'last_name' => $nameParts['last_name'],
                    'name' => trim($nameParts['first_name'] . ' ' . $nameParts['last_name']),
                    'email' => "tg_{$telegramId}@padel.local",
                    'phone' => $phone,
                    'password' => Hash::make('tg_' . $telegramId . '_' . time()),
                    'role' => 'player',
                    'rating' => 1000,
                    'level' => $level,
                ]);
                $this->stats['created']++;
            }
        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->newLine();
            $this->error("Ошибка для telegram_id {$telegramId}: " . $e->getMessage());
        }
    }

    /**
     * Нормализуем level (max 5.75)
     */
    protected function normalizeLevel($level): float
    {
        $level = (float) $level;
        
        if ($level < 1.0) {
            return 1.0;
        }
        
        if ($level > 5.75) {
            return 5.75;
        }
        
        return round($level, 2);
    }

    /**
     * Парсим full_name в first_name и last_name
     */
    protected function parseName(?string $fullName): array
    {
        if (empty($fullName)) {
            return [
                'first_name' => 'Игрок',
                'last_name' => '',
            ];
        }

        $parts = preg_split('/\s+/', trim($fullName), 2);

        return [
            'first_name' => $parts[0] ?? 'Игрок',
            'last_name' => $parts[1] ?? '',
        ];
    }

    /**
     * Нормализуем телефон к формату 7XXXXXXXXXX
     */
    protected function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Убираем всё кроме цифр
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Убираем + в начале если остался
        $phone = ltrim($phone, '+');

        // 8XXXXXXXXXX → 7XXXXXXXXXX
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7' . substr($phone, 1);
        }

        // Если 10 цифр, добавляем 7
        if (strlen($phone) === 10) {
            $phone = '7' . $phone;
        }

        return $phone;
    }
}