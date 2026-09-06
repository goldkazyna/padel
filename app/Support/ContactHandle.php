<?php

namespace App\Support;

/**
 * Приведение контактов к одному виду.
 *
 * Люди вводят ник как придётся: «@denis», «https://t.me/denis», «t.me/denis»,
 * «instagram.com/denis/». Хранить это как есть — значит потом не уметь ни
 * сравнить, ни собрать ссылку.
 */
class ContactHandle
{
    /** Ник в Telegram или Instagram: без «@», без адреса, без хвостового «/». */
    public static function username(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Отрезаем адрес целиком: t.me/denis, https://instagram.com/denis/
        $value = preg_replace('#^https?://#i', '', $value);
        $value = preg_replace('#^(www\.)?(t\.me|telegram\.me|instagram\.com)/#i', '', $value);
        $value = ltrim($value, '@');
        $value = trim($value, "/ \t\n\r\0\x0B");

        // Хвост вида ?igsh=... от ссылки-приглашения.
        $value = preg_split('/[?#]/', $value)[0];

        return $value === '' ? null : $value;
    }

    /**
     * Телефон WhatsApp в 11 цифр (7XXXXXXXXXX) или null, если непохоже на
     * казахстанский/российский номер.
     */
    public static function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }

        return (strlen($digits) === 11 && $digits[0] === '7') ? $digits : null;
    }
}
