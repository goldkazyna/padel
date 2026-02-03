<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MigrateRatings extends Command
{
    protected $signature = 'rating:migrate {--dry-run : Показать изменения без сохранения}';
    protected $description = 'Мигрировать рейтинги на новую систему (уровень × 1000)';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info("=== РЕЖИМ ПРОСМОТРА (без сохранения) ===\n");
        } else {
            if (!$this->confirm('Это обновит рейтинги ВСЕХ игроков. Продолжить?')) {
                return;
            }
        }

        $players = User::where('role', 'player')->get();
        
        $this->info("Найдено игроков: {$players->count()}\n");
        $this->info(str_pad('Имя', 30) . str_pad('Уровень', 10) . str_pad('Было', 10) . str_pad('Стало', 10) . 'Разница');
        $this->info(str_repeat('-', 70));

        $updated = 0;
        
        foreach ($players as $player) {
            $oldRating = $player->rating;
            $level = $player->level;
            
            // Новый рейтинг = уровень × 1000
            $newRating = (int) ($level * 1000) + 125;
            
            // Минимум 1000
            $newRating = max(1000, $newRating);
            
            $diff = $newRating - $oldRating;
            $diffStr = sprintf("%+d", $diff);
            
            $this->line(
                str_pad($player->full_name ?? $player->name, 30) .
                str_pad($level, 10) .
                str_pad($oldRating, 10) .
                str_pad($newRating, 10) .
                $diffStr
            );

            if (!$dryRun) {
                $player->update(['rating' => $newRating]);
                $updated++;
            }
        }

        $this->info(str_repeat('-', 70));
        
        if ($dryRun) {
            $this->warn("\nЭто был просмотр. Для применения запусти: php artisan rating:migrate");
        } else {
            $this->info("\nОбновлено игроков: {$updated}");
        }
    }
}