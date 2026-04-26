<?php

namespace App\Console\Commands;

use App\Models\RatingHistory;
use App\Models\Tournament;
use Illuminate\Console\Command;

class CleanOrphanRatingHistory extends Command
{
    protected $signature = 'rating-history:clean-orphans';

    protected $description = 'Удалить RatingHistory записи, у которых tournament_id указывает на удалённый турнир';

    public function handle(): int
    {
        $existingIds = Tournament::pluck('id')->all();

        $orphans = RatingHistory::whereNotNull('tournament_id')
            ->whereNotIn('tournament_id', $existingIds)
            ->count();

        if ($orphans === 0) {
            $this->info('Осиротевших записей не найдено.');
            return self::SUCCESS;
        }

        $this->warn("Найдено {$orphans} осиротевших записей. Удаляем…");

        $deleted = RatingHistory::whereNotNull('tournament_id')
            ->whereNotIn('tournament_id', $existingIds)
            ->delete();

        $this->info("Удалено: {$deleted}");
        return self::SUCCESS;
    }
}
