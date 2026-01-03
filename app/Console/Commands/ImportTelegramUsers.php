<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ImportTelegramUsers extends Command
{
    protected $signature = 'import:telegram-users {file}';
    protected $description = 'Import users from Telegram bot JSON export';

    public function handle()
    {
        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $json = file_get_contents($filePath);
        $users = json_decode($json, true);

        if (!$users) {
            $this->error("Invalid JSON file");
            return 1;
        }

        $this->info("Found " . count($users) . " users in file");

        $created = 0;
        $updated = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar(count($users));
        $bar->start();

        foreach ($users as $userData) {
            $bar->advance();

            // Пропускаем "Резерв" и отрицательные telegram_id
            if ($userData['telegram_id'] < 0) {
                $skipped++;
                continue;
            }

            // Пропускаем если нет telegram_id
            if (empty($userData['telegram_id'])) {
                $skipped++;
                continue;
            }

            // Разбираем имя
            $nameParts = $this->parseName($userData['full_name']);

            // Чистим телефон
            $phone = $this->cleanPhone($userData['phone_number']);

            // Уровень и рейтинг
            $level = $this->parseLevel($userData['player_level']);
            $rating = $this->levelToRating($level);

            // Ищем существующего по telegram_id
            $user = User::where('telegram_id', $userData['telegram_id'])->first();

            if ($user) {
                // Обновляем только если нужно
                $user->update([
                    'phone' => $phone,
                    'level' => $level,
                    'rating' => $rating,
                ]);
                $updated++;
            } else {
                // Ищем по телефону
                $user = User::where('phone', $phone)->first();

                if ($user) {
                    // Привязываем telegram_id
                    $user->update([
                        'telegram_id' => $userData['telegram_id'],
                        'level' => $level,
                        'rating' => $rating,
                    ]);
                    $updated++;
                } else {
                    // Создаём нового
                    $email = 'tg_' . $userData['telegram_id'] . '@padel.local';

                    User::create([
                        'first_name' => $nameParts['first_name'],
                        'last_name' => $nameParts['last_name'],
                        'name' => $userData['full_name'],
                        'email' => $email,
                        'phone' => $phone,
                        'telegram_id' => $userData['telegram_id'],
                        'password' => Hash::make('padel_' . $userData['telegram_id']),
                        'role' => 'player',
                        'level' => $level,
                        'rating' => $rating,
                    ]);
                    $created++;
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Import completed!");
        $this->table(
            ['Created', 'Updated', 'Skipped'],
            [[$created, $updated, $skipped]]
        );

        return 0;
    }

    /**
     * Разбираем ФИО
     */
    protected function parseName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName));

        if (count($parts) >= 2) {
            // "Фамилия Имя" или "Фамилия Имя Отчество"
            return [
                'first_name' => $parts[1] ?? $parts[0],
                'last_name' => $parts[0],
            ];
        }

        // Только одно слово
        return [
            'first_name' => $parts[0],
            'last_name' => '',
        ];
    }

    /**
     * Чистим телефон
     */
    protected function cleanPhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // Убираем всё кроме цифр
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Приводим к формату 7XXXXXXXXXX
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7' . substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Парсим уровень
     */
    protected function parseLevel($level): float
    {
        if (empty($level)) {
            return 1.0;
        }

        $level = (float) $level;

        // Ограничиваем разумными пределами
        if ($level < 1.0) return 1.0;
        if ($level > 5.75) return 5.75;

        return $level;
    }

    /**
     * Конвертируем уровень в рейтинг
     */
    protected function levelToRating(float $level): int
    {
        // Обратная формула от updateLevel
        $ratingMap = [
            1.0 => 700,
            1.25 => 850,
            1.5 => 950,
            1.75 => 1050,
            2.0 => 1150,
            2.25 => 1250,
            2.5 => 1350,
            2.75 => 1450,
            3.0 => 1550,
            3.25 => 1650,
            3.5 => 1750,
            3.75 => 1850,
            4.0 => 1950,
            4.25 => 2050,
            4.5 => 2150,
            4.75 => 2250,
            5.0 => 2350,
            5.25 => 2450,
            5.5 => 2550,
            5.75 => 2650,
        ];

        // Находим ближайший уровень
        $closestLevel = 1.0;
        $minDiff = abs($level - 1.0);

        foreach ($ratingMap as $l => $r) {
            $diff = abs($level - $l);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closestLevel = $l;
            }
        }

        return $ratingMap[$closestLevel];
    }
}
```

---

## Шаг 3: Скопируй JSON файл

Положи `users.json` в корень проекта:
```
C:\projects\padel\users.json