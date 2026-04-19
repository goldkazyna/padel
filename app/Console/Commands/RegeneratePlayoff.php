<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use App\Services\TeamTournamentService;
use Illuminate\Console\Command;

class RegeneratePlayoff extends Command
{
    protected $signature = 'tournament:regenerate-playoff {id : Tournament ID}';
    protected $description = 'Перегенерировать плей-офф парного турнира (удаляет существующие playoff-матчи)';

    public function handle(TeamTournamentService $service): int
    {
        $id = (int) $this->argument('id');
        $tournament = Tournament::find($id);

        if (!$tournament) {
            $this->error("Турнир #{$id} не найден");
            return self::FAILURE;
        }

        $this->info("Турнир: {$tournament->name}");
        $this->info("groups_count: {$tournament->groups_count}");
        $this->info("teams_advance: {$tournament->teams_advance}");
        $this->info("has_lower_bracket: " . ($tournament->has_lower_bracket ? 'YES' : 'NO'));
        $this->info("has_bronze_match: " . ($tournament->has_bronze_match ? 'YES' : 'NO'));

        $tournament->playoffMatches()->delete();
        $this->info("Старый плей-офф удалён.");

        $result = $service->generatePlayoff($tournament);

        if (!$result) {
            $this->error('Не удалось сгенерировать плей-офф. Проверьте что групповой этап полностью завершён.');
            return self::FAILURE;
        }

        $upper = $tournament->playoffMatches()->where('bracket', 'upper')->count();
        $lower = $tournament->playoffMatches()->where('bracket', 'lower')->count();
        $this->info("Создано матчей. Upper: {$upper}, Lower: {$lower}");

        return self::SUCCESS;
    }
}
