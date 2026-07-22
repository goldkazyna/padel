<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Восстановление пароля по телефону + SMS-код.
 * Механика SMS одна в одну как в мобильном приложении (MobileAuthController::sendCode):
 * код в кэш на 5 минут, текст "Padel KZ Ваш код OTP: {code}", тестовый код 1111 всегда работает.
 */
class PhonePasswordResetController extends Controller
{
    /** Показать страницу восстановления. */
    public function create()
    {
        return view('auth.forgot-password');
    }

    /** Шаг 1: отправить SMS-код на телефон существующего пользователя. */
    public function sendCode(Request $request)
    {
        $request->validate(['phone' => 'required|string']);
        $phone = $this->normalizePhone($request->phone);

        $user = User::where('phone', $phone)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь с таким номером не найден',
            ], 422);
        }

        $code = (string) random_int(1000, 9999);
        Cache::put("pwd_reset_{$phone}", $code, now()->addMinutes(5));

        $sent = app(SmsService::class)->send($phone, "Padel KZ Ваш код OTP: {$code}");
        if (!$sent) {
            Log::warning('Password reset SMS not sent', ['phone' => $this->maskPhone($phone)]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Код отправлен на номер ' . $this->maskPhone($phone),
        ]);
    }

    /** Шаг 2: проверить код и установить новый пароль, затем войти. */
    public function reset(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $phone = $this->normalizePhone($request->phone);

        // Тестовый код 1111 работает всегда (как в приложении).
        if ($request->code !== '1111') {
            $cached = Cache::get("pwd_reset_{$phone}");
            if (!$cached || !hash_equals($cached, $request->code)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Неверный или просроченный код',
                ], 422);
            }
        }

        $user = User::where('phone', $phone)->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Пользователь не найден'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();
        Cache::forget("pwd_reset_{$phone}");

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => 'Пароль изменён',
            'redirect' => route('dashboard'),
        ]);
    }

    /** Телефон → только цифры (как в MobileAuthController). */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);
        if ($length < 4) return $phone;
        return substr($phone, 0, 2) . str_repeat('*', $length - 4) . substr($phone, -2);
    }
}
