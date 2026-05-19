<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use App\Services\AmericanoService;
use Illuminate\Console\Command;

class DebugTournamentPreview extends Command
{
    protected $signature = 'tournament:debug-preview {id}';
    protected $description = 'Диагностика previewRatingChanges для турнира (зачем не показывается плей-офф)';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $tournament = Tournament::find($id);
        if (!$tournament) {
            $this->error("Турнир {$id} не найден");
            return self::FAILURE;
        }

        $this->info("Турнир #{$id} «{$tournament->name}»");
        $this->line("type: {$tournament->type}");
        $this->line("status: {$tournament->status}");
        $this->line("has_playoff (raw): " . var_export($tournament->has_playoff, true));
        $this->line("hasPlayoff() метод: " . var_export($tournament->hasPlayoff(), true));
        $this->line("");

        $allPlayoff = $tournament->playoffMatches()->get();
        $this->line("Всего playoff-матчей: {$allPlayoff->count()}");
        if ($allPlayoff->isEmpty()) {
            $this->warn('— нет playoff-матчей в БД');
        } else {
            foreach ($allPlayoff as $m) {
                $this->line(sprintf(
                    "  #%d | stage=%s | match_number=%s | status=%s | %s vs %s | %s+%s vs %s+%s",
                    $m->id,
                    var_export($m->stage, true),
                    var_export($m->match_number, true),
                    var_export($m->status, true),
                    $m->team1_score ?? '-',
                    $m->team2_score ?? '-',
                    $m->team1_player1_id ?? 'null',
                    $m->team1_player2_id ?? 'null',
                    $m->team2_player1_id ?? 'null',
                    $m->team2_player2_id ?? 'null',
                ));
            }
        }

        $this->line("");
        $completed = $tournament->playoffMatches()->where('status', 'completed')->get();
        $this->line("Playoff-матчей со status='completed': {$completed->count()}");

        $this->line("");
        $this->info('Вызываю AmericanoService::previewRatingChanges...');
        try {
            $service = app(AmericanoService::class);
            $preview = $service->previewRatingChanges($tournament);
            $this->line('Группы в превью: ' . implode(', ', array_keys($preview)));
            if (isset($preview['Плей-офф'])) {
                $playoffCount = count($preview['Плей-офф']);
                $this->info("Секция «Плей-офф»: {$playoffCount} игроков");
                foreach ($preview['Плей-офф'] as $playerId => $data) {
                    $matchCount = count($data['matches'] ?? []);
                    $this->line("  {$data['name']} (id={$playerId}): матчей={$matchCount}");
                    foreach (($data['matches'] ?? []) as $mInfo) {
                        $this->line("    - " . mb_substr($mInfo, 0, 200));
                    }
                }
            } else {
                $this->warn('Секция «Плей-офф» ОТСУТСТВУЕТ в выводе previewRatingChanges');
            }
        } catch (\Throwable $e) {
            $this->error('Исключение: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
        }

        return self::SUCCESS;
    }
}
