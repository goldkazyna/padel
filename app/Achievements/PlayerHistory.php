<?php

namespace App\Achievements;

use App\Models\RatingHistory;
use App\Models\User;
use App\Services\PlayerMatchHistory;
use Illuminate\Support\Collection;

/**
 * Вся история игрока, собранная один раз.
 *
 * Правила получают готовый снимок, а не ходят в базу сами: иначе пятнадцать
 * значков означали бы пятнадцать проходов по десяти таблицам форматов.
 */
class PlayerHistory
{
    /**
     * @param array<int, array<string, mixed>> $matches отсортированы по дате
     * @param Collection<int, RatingHistory> $ratingEntries
     * @param array<string, mixed> $tournamentStats
     */
    public function __construct(
        public readonly User $user,
        public readonly array $matches,
        public readonly Collection $ratingEntries,
        public readonly array $tournamentStats,
    ) {
    }

    public static function for(User $user): self
    {
        $matches = app(PlayerMatchHistory::class)->for($user);
        // Порядок по дате обязателен: от него считается серия побед подряд.
        usort($matches, fn ($a, $b) => $a['sort_date'] <=> $b['sort_date']);

        return new self(
            user: $user,
            matches: $matches,
            ratingEntries: RatingHistory::where('user_id', $user->id)
                ->orderBy('created_at')
                ->get(),
            tournamentStats: $user->getTournamentStats(),
        );
    }

    /**
     * Матчи, сгруппированные по турниру. Нужны значкам, которые смотрят
     * на турнир целиком, а не на отдельные матчи.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function matchesByTournament(): array
    {
        $grouped = [];
        foreach ($this->matches as $match) {
            $id = $match['tournament_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $grouped[$id][] = $match;
        }

        return $grouped;
    }
}
