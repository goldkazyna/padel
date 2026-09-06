<?php

namespace App\Support;

/**
 * Картинка превью для лендингов, которыми делятся: /t, /live, /l.
 *
 * Логотипы клубов лежат в базе по-разному: у одних «logos/x.jpg», у других
 * «/logos/x.jpg», у третьих полный http-адрес. Прежний код добавлял префикс
 * всегда и получал «/logos/logos/x.jpg» — мессенджер показывал карточку без
 * картинки, и никто этого не видел, потому что 404 отдавался молча.
 */
class ShareLogo
{
    /** Общая картинка, когда у клуба логотипа нет. */
    public const FALLBACK = 'logos/add-padel-almaty.jpg';

    public static function url(?string $logo): string
    {
        $logo = trim((string) $logo);

        if ($logo === '') {
            return asset(self::FALLBACK);
        }

        if (preg_match('#^https?://#', $logo)) {
            return $logo;
        }

        $path = ltrim($logo, '/');
        if (!str_starts_with($path, 'logos/')) {
            $path = 'logos/' . $path;
        }

        return asset($path);
    }
}
