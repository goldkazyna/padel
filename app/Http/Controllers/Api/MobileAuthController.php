<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramAuthToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MobileAuthController extends Controller
{
    /**
     * Отправить код на телефон
     * POST /api/mobile/auth/send-code
     */
    public function sendCode(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $phone = $this->normalizePhone($request->phone);

        // Ищем пользователя по телефону
        $user = User::where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь с таким номером не найден',
            ], 404);
        }

        // Генерируем код (пока тестовый 1111)
        $code = '1111'; // TODO: Заменить на реальную отправку SMS

        // Сохраняем код в кэш на 5 минут
        Cache::put("sms_code_{$phone}", $code, now()->addMinutes(5));

        return response()->json([
            'success' => true,
            'message' => 'Код отправлен на номер ' . $this->maskPhone($phone),
        ]);
    }

    /**
     * Проверить код и выдать токен
     * POST /api/mobile/auth/verify-code
     */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string',
        ]);

        $phone = $this->normalizePhone($request->phone);

        // Тестовый код 1111 работает всегда (пока не подключены реальные SMS)
        $isTestCode = $request->code === '1111';

        if (!$isTestCode) {
            // Получаем код из кэша
            $cachedCode = Cache::get("sms_code_{$phone}");

            if (!$cachedCode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Код истёк. Запросите новый код.',
                ], 400);
            }

            if ($cachedCode !== $request->code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный код',
                ], 400);
            }
        }

        // Находим пользователя
        $user = User::where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не найден',
            ], 404);
        }

        // Удаляем использованный код
        Cache::forget("sms_code_{$phone}");

        // Создаём токен Sanctum
        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
            ],
        ]);
    }

    /**
     * Выход (удаление токена)
     * POST /api/mobile/auth/logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Вы вышли из системы',
        ]);
    }

    /**
     * Текущий пользователь
     * GET /api/mobile/auth/user
     */
    public function user(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_name' => $user->level_name,
            ],
        ]);
    }

    /**
     * Инициализация авторизации через Telegram
     * POST /api/mobile/auth/telegram/init
     */
    public function telegramInit(Request $request)
    {
        $token = Str::uuid()->toString();

        TelegramAuthToken::create(['token' => $token]);

        $botUsername = config('services.telegram_mobile.bot_username');

        return response()->json([
            'token' => $token,
            'bot_url' => "https://t.me/{$botUsername}?start=auth_{$token}",
        ]);
    }

    /**
     * Проверка статуса авторизации через Telegram
     * GET /api/mobile/auth/telegram/check?token={token}
     */
    public function telegramCheck(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $authToken = TelegramAuthToken::notExpired()
            ->whereNotNull('user_id')
            ->where('token', $request->token)
            ->first();

        if (!$authToken) {
            return response()->json(['success' => false]);
        }

        $user = $authToken->user;

        // Удаляем использованный токен
        $authToken->delete();

        // Создаём Sanctum токен
        $sanctumToken = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $sanctumToken,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
            ],
        ]);
    }

    /**
     * Принятие пользовательского соглашения
     * POST /api/mobile/auth/accept-terms
     */
    public function acceptTerms(Request $request)
    {
        $request->validate([
            'version' => 'sometimes|string|max:20',
        ]);

        $user = $request->user();
        $user->update([
            'terms_accepted_at' => now(),
            'terms_version' => $request->input('version', '1.0'),
        ]);

        return response()->json([
            'success' => true,
            'terms_accepted_at' => $user->terms_accepted_at->toISOString(),
            'terms_version' => $user->terms_version,
        ]);
    }

    /**
     * Нормализация телефона (убираем всё кроме цифр)
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Маскируем телефон для отображения
     */
    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);
        if ($length < 4) return $phone;

        return substr($phone, 0, 2) . str_repeat('*', $length - 4) . substr($phone, -2);
    }
}
