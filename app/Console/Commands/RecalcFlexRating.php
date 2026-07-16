<?php

namespace App\Console\Commands;

use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Services\AmericanoFlexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Пересчёт рейтинга завершённого Americano Flex турнира БЕЗ матчей 0:0.
 *
 *   php artisan tournaments:recalc-flex {id}            # только показать
 *   php artisan tournaments:recalc-flex {id} --apply    # применить
 */
class RecalcFlexRating extends Command
{
    protected $signature = 'tournaments:recalc-flex {tournament} {--apply}';
    protected $description = 'Пересчитать рейтинг Americano Flex турнира без матчей 0:0 (показать/применить)';

    public function handle(AmericanoFlexService $flex): int
    {
        $t = Tournament::find($this->argument('tournament'));
        if (!$t || $t->type !== 'americano_flex') {
            $this->error('Турнир не найден или не americano_flex');
            return self::FAILURE;
        }
        if (!$t->is_rated) {
            $this->warn('Турнир нерейтинговый — рейтинг не начислялся, пересчитывать нечего.');
            return self::SUCCESS;
        }

        $newDeltas = $flex->recomputeRatingDeltas($t);
        $players = $t->americanoFlexPlayers()->with('user')->get()->keyBy('user_id');

        $rows = [];
        $adjustments = []; // user_id => [old_change, new_change, adj, cur_rating, new_rating, history_id]
        foreach ($players as $uid => $p) {
            $u = $p->user;
            if (!$u) continue;
            $history = RatingHistory::where('user_id', $uid)
                ->where('tournament_id', $t->id)
                ->orderByDesc('id')
                ->first();
            $oldChange = $history ? (int) $history->change : 0;
            $newChange = (int) ($newDeltas[$uid] ?? 0);
            $adj = $newChange - $oldChange;
            $curRating = (int) $u->rating;
            $newRating = max(1, $curRating + $adj);

            $rows[] = [
                $u->name,
                (int) ($p->rating_before ?? 0),
                $oldChange,
                $newChange,
                ($adj >= 0 ? '+' : '') . $adj,
                $curRating . ' → ' . $newRating,
            ];
            $adjustments[$uid] = compact('oldChange', 'newChange', 'adj', 'curRating', 'newRating') + [
                'history_id' => $history?->id,
                'rating_before' => (int) ($history->rating_before ?? $curRating),
            ];
        }

        $this->info("Турнир #{$t->id}: {$t->name}");
        $this->table(
            ['Игрок', 'rating_before', 'Старая Δ', 'Новая Δ (без 0:0)', 'Коррекция', 'Рейтинг'],
            $rows
        );

        if (!$this->option('apply')) {
            $this->warn('Режим показа. Чтобы применить — добавь --apply');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($adjustments) {
            foreach ($adjustments as $uid => $a) {
                if ($a['adj'] !== 0) {
                    \App\Models\User::where('id', $uid)->update(['rating' => $a['newRating']]);
                }
                if ($a['history_id']) {
                    RatingHistory::where('id', $a['history_id'])->update([
                        'change' => $a['newChange'],
                        'rating_after' => (int) $a['rating_before'] + $a['newChange'],
                    ]);
                }
            }
        });

        $this->info('Применено ✓');
        return self::SUCCESS;
    }
}
