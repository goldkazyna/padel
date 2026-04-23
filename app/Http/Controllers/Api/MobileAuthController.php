<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramAuthToken;
use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

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
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_verified' => (bool) $user->level_verified,
                'quiz_completed' => (bool) $user->quiz_completed,
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
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_verified' => (bool) $user->level_verified,
                'quiz_completed' => (bool) $user->quiz_completed,
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
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_verified' => (bool) $user->level_verified,
                'quiz_completed' => (bool) $user->quiz_completed,
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
     * Регистрация по email
     * POST /api/mobile/auth/register
     */
    public function register(Request $request)
    {
        // Убираем + из номера телефона до валидации
        if ($request->phone) {
            $request->merge(['phone' => ltrim($request->phone, '+')]);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', 'min:6'],
            'phone' => 'required|string|max:20|unique:users,phone',
            'city' => 'required|string|max:255',
        ], [
            'name.required' => 'Введите ФИО',
            'email.required' => 'Введите email',
            'email.email' => 'Введите корректный email',
            'email.unique' => 'Пользователь с таким email уже существует',
            'password.required' => 'Введите пароль',
            'password.confirmed' => 'Пароли не совпадают',
            'password.min' => 'Пароль должен быть не менее 6 символов',
            'phone.required' => 'Введите номер телефона',
            'phone.unique' => 'Пользователь с таким номером телефона уже существует',
            'city.required' => 'Выберите город',
        ]);

        $nameParts = explode(' ', trim($request->name), 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $user = User::create([
            'name' => $request->name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $request->email,
            'password' => $request->password,
            'phone' => $request->phone,
            'city' => $request->city,
            'role' => 'player',
            'rating' => 1000,
            'level' => 1.00,
        ]);

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_verified' => (bool) $user->level_verified,
                'quiz_completed' => (bool) $user->quiz_completed,
            ],
        ], 201);
    }

    /**
     * Вход по email или телефону
     * POST /api/mobile/auth/login
     */
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required_without:email|string',
            'email' => 'required_without:login|string',
            'password' => 'required|string',
        ]);

        $login = trim((string) ($request->input('login') ?? $request->input('email') ?? ''));
        $isEmail = str_contains($login, '@');
        $errorMessage = 'Неверный логин или пароль';

        if ($isEmail) {
            if (str_ends_with($login, '@padel.local')) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], 401);
            }

            if (!Auth::attempt(['email' => $login, 'password' => $request->password])) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], 401);
            }
        } else {
            $phone = $this->normalizePhone($login);
            if (strlen($phone) === 11 && $phone[0] === '8') {
                $phone = '7' . substr($phone, 1);
            } elseif (strlen($phone) === 10) {
                $phone = '7' . $phone;
            }

            if (strlen($phone) !== 11 || $phone[0] !== '7') {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], 401);
            }

            $user = User::where('phone', $phone)->first();
            if (!$user || !$user->password || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], 401);
            }

            Auth::login($user);
        }

        $user = Auth::user();
        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_verified' => (bool) $user->level_verified,
                'quiz_completed' => (bool) $user->quiz_completed,
            ],
        ]);
    }

    /**
     * Запрос сброса пароля
     * POST /api/mobile/auth/forgot-password
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
        ]);

        Password::sendResetLink($request->only('email'));

        // Всегда success (против enumeration)
        return response()->json([
            'success' => true,
            'message' => 'Если аккаунт с таким email существует, мы отправили ссылку для сброса пароля.',
        ]);
    }

    /**
     * Сброс пароля
     * POST /api/mobile/auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'token' => 'required|string',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                // Удаляем все токены — пользователь перелогинится
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Пароль успешно изменён.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Не удалось сбросить пароль. Проверьте данные или запросите новую ссылку.',
        ], 400);
    }

    /**
     * Удаление аккаунта (требование Apple)
     * DELETE /api/mobile/auth/account
     */
    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        // Для email-пользователей (у кого есть пароль) — требуем подтверждение
        if ($user->password) {
            $request->validate([
                'password' => 'required|string',
            ]);

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный пароль',
                ], 403);
            }
        }

        // Анонимизируем данные (не удаляем, чтобы сохранить историю турниров)
        $user->forceFill([
            'name' => 'Удалённый пользователь',
            'first_name' => 'Удалённый',
            'last_name' => 'пользователь',
            'email' => 'deleted_' . $user->id . '@padel.local',
            'phone' => null,
            'password' => null,
            'avatar' => null,
            'telegram_id' => null,
            'remember_token' => null,
        ])->save();

        // Удаляем все Sanctum токены
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Аккаунт удалён.',
        ]);
    }

    /**
     * Авторизация через Google (Sign In with Google)
     * POST /api/mobile/auth/google
     *
     * Принимает id_token от Google Sign-In SDK на клиенте, проверяет его через
     * Google tokeninfo endpoint, находит/создаёт юзера и выдаёт Sanctum токен.
     * - Ищем по google_id → если есть, логиним.
     * - Иначе по email → если есть, линкуем google_id к существующему юзеру.
     * - Иначе создаём нового (без телефона — юзер дозаполнит в профиле).
     */
    public function googleSignIn(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        // Проверяем токен через Google (верификация подписи + срока + audience).
        try {
            $response = Http::timeout(10)
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $request->id_token,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Google tokeninfo request failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Не удалось проверить токен Google. Попробуйте позже.',
            ], 503);
        }

        if (!$response->ok()) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный токен Google',
            ], 401);
        }

        $payload = $response->json();

        // Проверяем issuer.
        $iss = $payload['iss'] ?? '';
        if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный issuer токена',
            ], 401);
        }

        // Проверяем aud: должен совпадать с нашим Web-client ID.
        $expectedAud = config('services.google.client_id');
        if ($expectedAud && ($payload['aud'] ?? null) !== $expectedAud) {
            Log::warning('Google id_token aud mismatch', [
                'expected' => $expectedAud,
                'got' => $payload['aud'] ?? null,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Токен не для этого приложения',
            ], 401);
        }

        // Проверяем срок действия.
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return response()->json([
                'success' => false,
                'message' => 'Токен Google истёк',
            ], 401);
        }

        $googleId = $payload['sub'] ?? null;
        $email = $payload['email'] ?? null;
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $name = trim($payload['name'] ?? '');
        $givenName = trim($payload['given_name'] ?? '');
        $familyName = trim($payload['family_name'] ?? '');
        $picture = $payload['picture'] ?? null;

        if (!$googleId) {
            return response()->json([
                'success' => false,
                'message' => 'Google не вернул идентификатор пользователя',
            ], 401);
        }

        // 1) Ищем по google_id.
        $user = User::where('google_id', $googleId)->first();

        // 2) Иначе по email (линкуем к существующему).
        if (!$user && $email && $emailVerified) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->google_id = $googleId;
                if (!$user->avatar && $picture) {
                    $user->avatar = $picture;
                }
                $user->save();
            }
        }

        // 3) Иначе создаём нового (без телефона и пароля).
        if (!$user) {
            $fallbackName = $name !== '' ? $name : trim("$givenName $familyName");
            if ($fallbackName === '' && $email) {
                $fallbackName = strstr($email, '@', true);
            }
            if ($fallbackName === '') {
                $fallbackName = 'Игрок';
            }

            $user = User::create([
                'name' => $fallbackName,
                'first_name' => $givenName ?: $fallbackName,
                'last_name' => $familyName ?: '',
                'email' => $email,
                'google_id' => $googleId,
                'avatar' => $picture,
                'role' => 'player',
                'rating' => 1000,
                'level' => 1.00,
            ]);
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_verified' => (bool) $user->level_verified,
                'quiz_completed' => (bool) $user->quiz_completed,
                'level_name' => $user->level_name,
            ],
        ]);
    }

    /**
     * Авторизация через Apple (Sign In with Apple)
     * POST /api/mobile/auth/apple
     *
     * Принимает identity_token от Apple SDK (JWT RS256, подписан приватным
     * ключом Apple), опционально first_name/last_name (Apple отдаёт их только
     * при ПЕРВОМ логине — мы сохраняем их тогда и больше не получим).
     *
     * Верификация: скачиваем публичные ключи Apple (кешируем на сутки),
     * проверяем подпись JWT, iss, aud, exp. Ищем юзера по apple_id → email
     * → создаём нового.
     */
    public function appleSignIn(Request $request)
    {
        $request->validate([
            'identity_token' => 'required|string',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
        ]);

        // 1) Получаем JWK-ключи Apple (с кешем на 24 часа).
        try {
            $keys = Cache::remember('apple_auth_jwks', now()->addDay(), function () {
                $response = Http::timeout(10)->get('https://appleid.apple.com/auth/keys');
                if (!$response->ok()) {
                    throw new \RuntimeException('Apple JWKs fetch failed: ' . $response->status());
                }
                return JWK::parseKeySet($response->json());
            });
        } catch (\Throwable $e) {
            Log::warning('Apple JWKs fetch failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Не удалось получить ключи Apple. Попробуйте позже.',
            ], 503);
        }

        // 2) Декодируем и верифицируем JWT.
        try {
            $payload = JWT::decode($request->identity_token, $keys);
        } catch (\Throwable $e) {
            Log::info('Apple identity_token decode failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Неверный токен Apple',
            ], 401);
        }

        // 3) Проверяем iss.
        if (($payload->iss ?? '') !== 'https://appleid.apple.com') {
            return response()->json([
                'success' => false,
                'message' => 'Неверный issuer токена Apple',
            ], 401);
        }

        // 4) Проверяем aud (bundle_id).
        $allowedAuds = array_filter(array_map('trim', explode(',', (string) config('services.apple.bundle_id', ''))));
        if (!empty($allowedAuds) && !in_array($payload->aud ?? '', $allowedAuds, true)) {
            Log::warning('Apple identity_token aud mismatch', [
                'expected' => $allowedAuds,
                'got' => $payload->aud ?? null,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Токен не для этого приложения',
            ], 401);
        }

        // 5) Срок действия.
        if (isset($payload->exp) && (int) $payload->exp < time()) {
            return response()->json([
                'success' => false,
                'message' => 'Токен Apple истёк',
            ], 401);
        }

        $appleId = $payload->sub ?? null;
        $email = $payload->email ?? null;
        $emailVerified = filter_var($payload->email_verified ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$appleId) {
            return response()->json([
                'success' => false,
                'message' => 'Apple не вернул идентификатор пользователя',
            ], 401);
        }

        // Имя из тела запроса (iOS отдаёт его только при первом логине).
        $firstName = trim((string) $request->input('first_name', ''));
        $lastName = trim((string) $request->input('last_name', ''));

        // 1) Ищем по apple_id.
        $user = User::where('apple_id', $appleId)->first();

        // 2) Иначе по email (линкуем).
        if (!$user && $email && $emailVerified) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->apple_id = $appleId;
                $user->save();
            }
        }

        // 3) Иначе создаём нового.
        if (!$user) {
            $fullName = trim("$firstName $lastName");
            if ($fullName === '' && $email) {
                $fullName = strstr($email, '@', true);
            }
            if ($fullName === '') {
                $fullName = 'Игрок';
            }

            $user = User::create([
                'name' => $fullName,
                'first_name' => $firstName ?: $fullName,
                'last_name' => $lastName ?: '',
                'email' => $email,
                'apple_id' => $appleId,
                'role' => 'player',
                'rating' => 1000,
                'level' => 1.00,
            ]);
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'rating' => $user->rating,
                'level' => $user->level,
                'level_verified' => (bool) $user->level_verified,
                'quiz_completed' => (bool) $user->quiz_completed,
                'level_name' => $user->level_name,
            ],
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
