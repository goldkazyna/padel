<?php

use App\Models\RatingHistory;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: для каждого юзера, у которого users.rating расходится с
 * последним rating_after в rating_history — добавляем компенсирующую
 * запись «Ручная корректировка» (tournament_id = null).
 *
 * Идемпотентно: миграцию можно прогнать повторно — она ничего лишнего
 * не добавит, потому что после первого прогона расхождений не останется.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Все юзеры у которых есть хоть одна запись rating_history
        $userIds = RatingHistory::query()
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (!$user) continue;

            $last = RatingHistory::where('user_id', $userId)
                ->orderByDesc('id')
                ->first();

            if (!$last) continue;

            $actualRating = (int) ($user->rating ?? 0);
            $lastRating = (int) ($last->rating_after ?? 0);

            if ($actualRating === $lastRating) continue;

            RatingHistory::create([
                'user_id' => $userId,
                'tournament_id' => null,
                'rating_before' => $lastRating,
                'rating_after' => $actualRating,
                'change' => $actualRating - $lastRating,
                'reason' => 'Ручная корректировка',
            ]);
        }
    }

    public function down(): void
    {
        // Сложно откатить однозначно — записи с tournament_id=null могут быть
        // и от новых ручных правок. Намеренно не делаем ничего.
    }
};
