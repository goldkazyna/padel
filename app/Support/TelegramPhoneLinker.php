<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Защита от дублей аккаунтов по телефону при входе/регистрации через Telegram.
 *
 * Проблема: telegram-флоу ключуют аккаунт по telegram_id и раньше писали phone
 * напрямую, не проверяя, что номер уже привязан к другому аккаунту → появлялись
 * два юзера с одним телефоном. Хелпер централизует проверку и слияние, чтобы
 * во всех точках (бот-webhook, Mini App, веб-вход) поведение было одинаковым.
 */
class TelegramPhoneLinker
{
    /** Нормализовать телефон — оставить только цифры. */
    public static function normalize(?string $phone): string
    {
        return preg_replace('/[^0-9]/', '', (string) $phone);
    }

    /** Найти аккаунт с этим (уже нормализованным) телефоном, кроме указанного. */
    public static function otherAccountWithPhone(string $phone, ?int $exceptId = null): ?User
    {
        if ($phone === '') {
            return null;
        }

        return User::where('phone', $phone)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->orderBy('id')
            ->first();
    }

    /**
     * Привязать телефон к telegram-аккаунту $user с защитой от дублей.
     * Если номер уже принадлежит другому аккаунту — сливает telegram-идентичность
     * $user в тот аккаунт и возвращает его. Иначе просто ставит телефон на $user.
     *
     * Токены/сессию не трогает — это специфика конкретного флоу.
     *
     * @return array{0: User, 1: bool} [канонический аккаунт, было ли слияние]
     */
    public static function linkPhone(User $user, ?string $rawPhone): array
    {
        $phone = self::normalize($rawPhone);
        if ($phone === '') {
            return [$user, false];
        }

        $existing = self::otherAccountWithPhone($phone, $user->id);
        if (!$existing) {
            $user->update(['phone' => $phone]);
            return [$user, false];
        }

        self::adoptTelegramIdentity($existing, $user->telegram_id, $user->telegram_username ?? null);

        // Отвязываем telegram_id у дубля, если он совпал с перенесённым, иначе
        // поиск по telegram_id снова попадал бы на дубль и коллизия повторялась.
        if ((string) $user->telegram_id !== ''
            && (string) $user->telegram_id === (string) $existing->telegram_id) {
            $user->update(['telegram_id' => null]);
        }

        Log::info('Telegram phone merge: дубль подавлен', [
            'duplicate_id' => $user->id,
            'existing_id' => $existing->id,
            'phone' => $phone,
        ]);

        return [$existing, true];
    }

    /**
     * Привязать telegram_id/username к существующему аккаунту, если их ещё нет.
     * Подходит и веб-флоу, где telegram-аккаунта ещё не создано.
     */
    public static function adoptTelegramIdentity(User $existing, ?string $telegramId, ?string $username): void
    {
        $attrs = [];
        if (empty($existing->telegram_id) && !empty($telegramId)) {
            $attrs['telegram_id'] = $telegramId;
        }
        if (empty($existing->telegram_username) && !empty($username)) {
            $attrs['telegram_username'] = $username;
        }
        if ($attrs) {
            $existing->update($attrs);
        }
    }
}
