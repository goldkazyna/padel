<?php

namespace App\Console\Commands;

use App\Models\RatingHistory;
use App\Models\User;
use App\Support\PlayerActivity;
use App\Support\RatingDecay;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Списать рейтинг тем, кто перестал играть.
 *
 * Рейтинг должен что-то значить: человек, не выходивший на корт полгода,
 * стоял в таблице рядом с теми, кто играет каждую неделю. Правила живут в
 * App\Support\RatingDecay, здесь — только проход по базе.
 *
 * Заодно чинит last_played_at: колонка существует с 2025 года, но её
 * заполняли только поединки, и на проде она была у 14 человек из трёх тысяч.
 */
class DecayInactiveRatings extends Command
{
    protected $signature = 'rating:decay-inactive
        {--dry : посчитать и показать, ничего не меняя}
        {--limit=0 : ограничить число списаний (0 — без ограничения)}';

    protected $description = 'Снять рейтинг за простой и обновить дату последней игры';

    public function handle(): int
    {
        $now = Carbon::now();
        $dry = (bool) $this->option('dry');
        $limit = (int) $this->option('limit');

        $this->info('Считаю дату последней игры…');
        $played = PlayerActivity::lastPlayedMap();
        $this->line('  игравших когда-либо: ' . count($played));

        $touched = 0;
        $decayed = 0;
        $total = 0;

        User::query()->orderBy('id')->chunkById(500, function ($users) use (
            $played, $now, $dry, $limit, &$touched, &$decayed, &$total
        ) {
            foreach ($users as $user) {
                // Берём позднюю из двух дат. Посчитанная по участию —
                // основная, но у поединков дату пишет EloService, и терять
                // её нельзя: иначе человек, игравший только их, попадёт под
                // списание как «не игравший никогда».
                $lastPlayed = $played[$user->id] ?? null;
                $stored = $user->last_played_at
                    ? Carbon::parse($user->last_played_at)
                    : null;
                if ($stored && (!$lastPlayed || $stored->gt($lastPlayed))) {
                    $lastPlayed = $stored;
                }

                // Дату последней игры чиним всем, даже тем, кого списание
                // не касается: по ней в профиле считается предупреждение.
                if ($lastPlayed && (string) $stored !== (string) $lastPlayed) {
                    if (!$dry) {
                        $user->forceFill(['last_played_at' => $lastPlayed])->saveQuietly();
                    }
                    $touched++;
                }

                if ($limit > 0 && $decayed >= $limit) {
                    continue;
                }

                $rating = (int) $user->rating;
                $amount = RatingDecay::amountFor($rating);
                if ($amount <= 0) {
                    continue;
                }

                $lastDecay = $user->rating_decayed_at
                    ? Carbon::parse($user->rating_decayed_at)
                    : null;

                if (!RatingDecay::isDue($lastPlayed, $lastDecay, $now)) {
                    continue;
                }

                $decayed++;
                $total += $amount;

                if ($dry) {
                    continue;
                }

                $this->charge($user, $rating, $amount, $now);
            }
        });

        $head = $dry ? 'Посчитано (ничего не изменено)' : 'Готово';
        $this->info("$head: дат обновлено $touched, списаний $decayed, всего −$total.");

        if (!$dry && $decayed > 0) {
            Log::info('rating:decay-inactive', ['users' => $decayed, 'total' => $total]);
        }

        return self::SUCCESS;
    }

    /**
     * Снять рейтинг, пересчитать уровень и оставить запись в истории —
     * иначе человек увидит просадку и не поймёт, откуда она.
     */
    private function charge(User $user, int $rating, int $amount, Carbon $now): void
    {
        $after = $rating - $amount;

        DB::transaction(function () use ($user, $rating, $after, $amount, $now) {
            $user->forceFill([
                'rating' => $after,
                'level' => RatingDecay::levelFor($after),
                'rating_decayed_at' => $now,
            ])->saveQuietly();

            RatingHistory::create([
                'user_id' => $user->id,
                'rating_before' => $rating,
                'rating_after' => $after,
                'change' => -$amount,
                'reason' => RatingHistory::REASON_DECAY,
            ]);
        });
    }
}
