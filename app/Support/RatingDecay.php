<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Списание рейтинга за простой.
 *
 * Рейтинг должен что-то значить: человек, не игравший полгода, стоял в
 * таблице рядом с теми, кто играет каждую неделю. Правила:
 *
 * - первое списание — на 60-й день без игры;
 * - дальше по −50 каждые 30 дней, пока человек не сыграет;
 * - ниже 1000 не опускаем — тот же порог, что у обычного расчёта рейтинга;
 * - сыграл — счётчик обнуляется сам, ничего сбрасывать руками не нужно.
 *
 * Отсчёт для всех начался в день запуска ([START]), а не от последней игры:
 * иначе в первый же вечер у половины базы рейтинг обвалился бы на несколько
 * сотен без объяснения.
 */
class RatingDecay
{
    /** Сколько снимаем за одно списание. */
    public const AMOUNT = 50;

    /** Дней простоя до первого списания. */
    public const FIRST_AFTER_DAYS = 60;

    /** Дней между следующими списаниями. */
    public const NEXT_EVERY_DAYS = 30;

    /** Ниже этой отметки не опускаем. */
    public const MIN_RATING = 1000;

    /** С этого дня простоя предупреждаем в профиле. */
    public const WARN_AFTER_DAYS = 45;

    /**
     * День, с которого система заработала: раньше него простой не считаем.
     */
    public static function startedAt(): Carbon
    {
        return Carbon::parse(config('rating.decay_started_at'))->startOfDay();
    }

    /**
     * От какой даты считать простой: последняя игра, но не раньше запуска
     * системы, и не раньше последнего списания.
     */
    public static function countFrom(?Carbon $lastPlayedAt, ?Carbon $lastDecayAt): Carbon
    {
        $from = $lastPlayedAt && $lastPlayedAt->gt(self::startedAt())
            ? $lastPlayedAt->copy()
            : self::startedAt();

        if ($lastDecayAt && $lastDecayAt->gt($from)) {
            return $lastDecayAt->copy();
        }

        return $from;
    }

    /** Дней простоя на текущий момент. */
    public static function idleDays(?Carbon $lastPlayedAt, ?Carbon $now = null): int
    {
        $now = $now ?? Carbon::now();
        $from = $lastPlayedAt && $lastPlayedAt->gt(self::startedAt())
            ? $lastPlayedAt
            : self::startedAt();

        return max(0, (int) $from->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()));
    }

    /**
     * Пора ли списывать. Первое списание — через 60 дней после последней
     * игры, следующие — через 30 после предыдущего списания.
     */
    public static function isDue(
        ?Carbon $lastPlayedAt,
        ?Carbon $lastDecayAt,
        ?Carbon $now = null
    ): bool {
        $now = $now ?? Carbon::now();
        $from = self::countFrom($lastPlayedAt, $lastDecayAt);

        // Списание было позже последней игры — значит человек так и не
        // вернулся, и ждём короткий шаг.
        $step = ($lastDecayAt && (!$lastPlayedAt || $lastDecayAt->gte($lastPlayedAt)))
            ? self::NEXT_EVERY_DAYS
            : self::FIRST_AFTER_DAYS;

        return $from->copy()->startOfDay()->addDays($step)->lte($now->copy()->startOfDay());
    }

    /**
     * Сколько дней осталось до ближайшего списания. Ноль — уже пора.
     */
    public static function daysUntilDecay(
        ?Carbon $lastPlayedAt,
        ?Carbon $lastDecayAt,
        ?Carbon $now = null
    ): int {
        $now = $now ?? Carbon::now();
        $from = self::countFrom($lastPlayedAt, $lastDecayAt);
        $step = ($lastDecayAt && (!$lastPlayedAt || $lastDecayAt->gte($lastPlayedAt)))
            ? self::NEXT_EVERY_DAYS
            : self::FIRST_AFTER_DAYS;

        $due = $from->copy()->startOfDay()->addDays($step);

        return max(0, (int) $now->copy()->startOfDay()->diffInDays($due, false));
    }

    /** Сколько реально спишется с этого рейтинга (с учётом порога). */
    public static function amountFor(int $rating): int
    {
        return max(0, min(self::AMOUNT, $rating - self::MIN_RATING));
    }

    /** Уровень по рейтингу — та же формула, что в RatingCalculator. */
    public static function levelFor(int $rating): float
    {
        return max(1.0, min(5.75, floor($rating / 250) * 0.25));
    }

    /**
     * Что показать в профиле: предупреждение появляется с 45-го дня и
     * только у тех, кому есть что терять.
     *
     * @return array<string, mixed>
     */
    public static function profileBlock(User $user, ?Carbon $lastPlayedAt): array
    {
        $lastDecayAt = $user->rating_decayed_at ? Carbon::parse($user->rating_decayed_at) : null;
        $idle = self::idleDays($lastPlayedAt);

        return [
            'idle_days' => $idle,
            'last_played_at' => $lastPlayedAt?->toIso8601String(),
            'days_until_decay' => self::daysUntilDecay($lastPlayedAt, $lastDecayAt),
            'amount' => self::amountFor((int) $user->rating),
            'warn' => $idle >= self::WARN_AFTER_DAYS
                && self::amountFor((int) $user->rating) > 0,
            // Уже списывали хотя бы раз за этот простой.
            'decayed' => $lastDecayAt !== null
                && (!$lastPlayedAt || $lastDecayAt->gte($lastPlayedAt)),
        ];
    }
}
