<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Часы клуба (Алматы, UTC+5) — там, где дата означает «когда играем».
 *
 * В базе такие даты лежат местными часами, а `app.timezone` у нас UTC.
 * Из-за этого две ошибки повторялись из фичи в фичу:
 *
 *  - сравнение с `now()` промахивалось на пять часов (начавшееся считалось
 *    предстоящим ещё полдня);
 *  - `toIso8601String()` отдавал «20:00+00:00», приложение звало `toLocal()`
 *    и показывало 01:00 следующего дня.
 *
 * Поэтому: сравниваем через {@see now()}, отдаём наружу через {@see iso()}.
 * Настоящие метки событий (когда создано, когда отправлено) — обычный UTC,
 * их сюда тащить не надо.
 */
class ClubTime
{
    public const TZ = 'Asia/Almaty';

    /** «Сейчас» в тех же часах, в каких лежат даты в базе. */
    public static function now(): Carbon
    {
        return Carbon::now(self::TZ);
    }

    /** Метка со смещением клуба: 20:00 остаётся 20:00 и в приложении. */
    public static function iso(?DateTimeInterface $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        return Carbon::instance($moment)->shiftTimezone(self::TZ)->toIso8601String();
    }
}
