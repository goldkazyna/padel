<?php

namespace App\Support;

use App\Models\Club;

/**
 * Управление видимостью телефонов в веб-админке клуба.
 *
 * Если у клуба установлен флаг hide_phones — админы/модераторы этого клуба
 * не видят номера телефонов игроков/клиентов нигде (списки, таблицы, экспорт,
 * автокомплит). Супер-админ видит телефоны всегда.
 */
class PhoneVisibility
{
    /** Кеш решения на время запроса, чтобы не дёргать БД на каждый телефон в таблице. */
    private static ?bool $cached = null;

    /** Скрыты ли телефоны для текущего залогиненного пользователя. */
    public static function hiddenForCurrentUser(): bool
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $user = auth()->user();
        if (!$user) {
            return self::$cached = false;
        }

        // Супер-админ видит телефоны всегда
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return self::$cached = false;
        }

        $club = null;
        if (method_exists($user, 'isClubModerator') && $user->isClubModerator()) {
            $club = $user->moderatorClubs()->first();
        } elseif (method_exists($user, 'adminClubs')) {
            $club = $user->adminClubs()->first();
        }

        return self::$cached = ($club?->hide_phones === true);
    }

    /** Скрыты ли телефоны для конкретного клуба (когда контекст клуба известен явно). */
    public static function hiddenForClub(?Club $club): bool
    {
        $user = auth()->user();
        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return false;
        }
        return $club?->hide_phones === true;
    }

    /**
     * Телефон для отображения. Если скрыт — «скрыт», если пусто — «—».
     * $formatted=true применяет красивое форматирование (+7 777 ...).
     */
    public static function display(?string $phone, bool $formatted = false): string
    {
        if (empty($phone)) {
            return '—';
        }
        if (self::hiddenForCurrentUser()) {
            return 'скрыт';
        }
        return $formatted ? self::format($phone) : $phone;
    }

    /** Красивый формат номера: 77001234567 → +7 700 123 45 67. */
    public static function format(string $phone): string
    {
        // Оставляем только цифры — чтобы уже имеющийся «+» не удваивался.
        $digits = preg_replace('/\D+/', '', $phone);
        return '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $digits);
    }

    /** Для JSON/экспорта: вернуть телефон или null/маску, если скрыт. */
    public static function forExport(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }
        return self::hiddenForCurrentUser() ? 'скрыт' : $phone;
    }
}
