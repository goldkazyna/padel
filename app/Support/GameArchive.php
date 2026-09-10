<?php

namespace App\Support;

use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;

/**
 * Когда игра уходит в архив.
 *
 * Сама по себе игра не закрывается: организатор мог не нажать «Завершить»,
 * а корт давно освободился. Поэтому кроме завершённых и отменённых в архив
 * уезжают те, чьё время вышло: конец плюс несколько часов на дозаполнение
 * счёта. Часы в базе — местные (Алматы), сравниваем с местным «сейчас».
 */
class GameArchive
{
    /** Сколько ждём после конца игры, прежде чем убрать её из списка. */
    public const GRACE_HOURS = 4;

    /** Момент, старее которого игра считается прошедшей. */
    public static function cutoff(): \Illuminate\Support\Carbon
    {
        return now('Asia/Almaty')->subHours(self::GRACE_HOURS);
    }

    /** Прошедшие: завершённые, отменённые и те, чьё время вышло. */
    public static function archived(Builder $query): Builder
    {
        $cutoff = self::cutoff();

        return $query->where(function ($q) use ($cutoff) {
            $q->whereIn('status', [Game::STATUS_FINISHED, Game::STATUS_CANCELLED])
                ->orWhereRaw('COALESCE(ends_at, starts_at) < ?', [$cutoff]);
        });
    }

    /** Живые: всё остальное — предстоящие, идущие, ждущие счёт. */
    public static function live(Builder $query): Builder
    {
        $cutoff = self::cutoff();

        return $query->whereNotIn('status', [Game::STATUS_FINISHED, Game::STATUS_CANCELLED])
            ->whereRaw('COALESCE(ends_at, starts_at) >= ?', [$cutoff]);
    }
}
