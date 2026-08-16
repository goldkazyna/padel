<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use App\Services\AmericanoService;
use App\Services\MexicanoService;
use App\Services\TeamTournamentService;
use Illuminate\Console\Command;

/**
 * Пересобрать плей-офф, не трогая групповой этап.
 *
 * Нужна, когда сетку надо построить заново по уже сыгранным группам:
 * поменялись правила посева, организатор ошибся в счёте плей-офф,
 * турнир пересобирают после правки. Счета групповых матчей остаются на месте.
 */
class RegeneratePlayoff extends Command
{
    protected $signature = 'tournament:regenerate-playoff
                            {id : ID турнира}
                            {--force : не спрашивать подтверждения}';

    protected $description = 'Пересобрать плей-офф турнира (групповой этап не трогается)';

    public function handle(): int
    {
        $tournament = Tournament::find((int) $this->argument('id'));

        if (!$tournament) {
            $this->error("Турнир #{$this->argument('id')} не найден");

            return self::FAILURE;
        }

        $service = $this->serviceFor($tournament);
        if (!$service) {
            $this->error("Для формата «{$tournament->type}» пересборка плей-офф не поддерживается");

            return self::FAILURE;
        }

        $this->line("Турнир: {$tournament->name} (#{$tournament->id})");
        $this->line("Формат: {$tournament->type}, групп: {$tournament->groups_count}, статус: {$tournament->status}");

        $existing = $tournament->playoffMatches()->count();
        $played = $tournament->playoffMatches()->where('status', 'completed')->count();
        $this->line("Матчей плей-офф сейчас: {$existing}, из них с внесённым счётом: {$played}");

        if ($played > 0 && !$this->option('force')) {
            // Счета плей-офф пропадут безвозвратно: групповой этап не трогаем,
            // но введённые результаты сетки восстановить будет неоткуда.
            if (!$this->confirm("Счёт {$played} матчей плей-офф будет потерян. Продолжить?", false)) {
                $this->line('Отменено.');

                return self::SUCCESS;
            }
        }

        $tournament->playoffMatches()->delete();
        $this->info('Старый плей-офф удалён.');

        // canGeneratePlayoff проверяет, что сетки ещё нет, — поэтому только после удаления.
        if (!$service->generatePlayoff($tournament->fresh())) {
            $this->error('Не удалось собрать плей-офф. Проверьте, что все групповые матчи сыграны, '
                . 'а участников хватает на сетку.');

            return self::FAILURE;
        }

        $fresh = $tournament->fresh();
        foreach ($fresh->playoffMatches()->orderBy('id')->get() as $match) {
            $this->line(sprintf(
                '  %-16s #%d  %s',
                $match->stage,
                $match->match_number,
                $this->describe($match)
            ));
        }

        $this->info('Готово. Матчей создано: ' . $fresh->playoffMatches()->count());

        return self::SUCCESS;
    }

    private function serviceFor(Tournament $tournament): mixed
    {
        return match (true) {
            $tournament->type === 'americano' => app(AmericanoService::class),
            $tournament->type === 'mexicano' => app(MexicanoService::class),
            $tournament->type === 'team' => app(TeamTournamentService::class),
            default => null,
        };
    }

    /** Кто с кем — чтобы результат было видно прямо в консоли. */
    private function describe($match): string
    {
        $side = fn ($p1, $p2, $source) => $p1
            ? trim(($p1->name ?? '?') . ' / ' . ($p2->name ?? '?'))
            : ($source ?: 'ожидание');

        return $side($match->team1Player1, $match->team1Player2, $match->team1_source)
            . '  vs  '
            . $side($match->team2Player1, $match->team2Player2, $match->team2_source);
    }
}
