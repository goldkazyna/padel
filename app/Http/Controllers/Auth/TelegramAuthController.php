<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class TelegramAuthController extends Controller
{
    /**
     * Обработка авторизации через Telegram
     */
    public function callback(Request $request)
    {
        $data = $request->all();

        // Проверяем подпись от Telegram
        if (!$this->checkTelegramAuth($data)) {
            return redirect('/login')->with('error', 'Ошибка авторизации Telegram');
        }

        $telegramId = $data['id'];

        // Ищем пользователя по telegram_id
        $user = User::where('telegram_id', $telegramId)->first();

        if ($user) {
            // Обновляем данные
            $user->update([
                'telegram_username' => $data['username'] ?? null,
            ]);

            Auth::login($user, true);

            return redirect('/dashboard')->with('success', 'Добро пожаловать, ' . $user->first_name . '!');
        }

        // Пользователь не найден — предлагаем зарегистрироваться или привязать аккаунт
        return redirect('/register/telegram')->with('telegram_data', $data);
    }

    /**
     * Страница завершения регистрации через Telegram
     */
    public function showRegisterForm(Request $request)
    {
        $telegramData = session('telegram_data');

        if (!$telegramData) {
            return redirect('/login');
        }

        return view('auth.telegram-register', compact('telegramData'));
    }

    /**
     * Завершение регистрации
     */
    public function completeRegistration(Request $request)
    {
        $telegramData = session('telegram_data');

        if (!$telegramData) {
            return redirect('/login')->with('error', 'Сессия истекла');
        }

        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string',
        ]);

        // Разбираем имя из Telegram
        $firstName = $telegramData['first_name'] ?? '';
        $lastName = $telegramData['last_name'] ?? '';
        $fullName = trim($firstName . ' ' . $lastName);

        // Защита от дублей: если номер уже привязан к существующему аккаунту —
        // не создаём второй, а привязываем telegram и логиним в него.
        $phone = \App\Support\TelegramPhoneLinker::normalize($validated['phone'] ?? null);
        $existing = $phone !== ''
            ? \App\Support\TelegramPhoneLinker::otherAccountWithPhone($phone)
            : null;

        if ($existing) {
            \App\Support\TelegramPhoneLinker::adoptTelegramIdentity(
                $existing,
                (string) $telegramData['id'],
                $telegramData['username'] ?? null
            );

            Auth::login($existing, true);
            session()->forget('telegram_data');

            return redirect('/dashboard')->with('success', 'Аккаунт с этим номером уже существует — вы вошли в него.');
        }

        $user = User::create([
            'first_name' => $firstName ?: 'Игрок',
            'last_name' => $lastName ?: $telegramData['id'],
            'name' => $fullName ?: 'Игрок ' . $telegramData['id'],
            'email' => $validated['email'],
            'phone' => $phone ?: null,
            'telegram_id' => $telegramData['id'],
            'telegram_username' => $telegramData['username'] ?? null,
            'password' => Hash::make('tg_' . $telegramData['id'] . '_' . time()),
            'role' => 'player',
            'rating' => 1000,
            'level' => 1.0,
        ]);

        Auth::login($user, true);

        session()->forget('telegram_data');

        return redirect('/dashboard')->with('success', 'Регистрация завершена!');
    }

    /**
     * Проверка подписи от Telegram
     */
    protected function checkTelegramAuth(array $data): bool
    {
        $botToken = config('services.telegram.bot_token');

        if (empty($botToken)) {
            return false;
        }

        $checkHash = $data['hash'] ?? '';
        unset($data['hash']);

        $dataCheckArr = [];
        foreach ($data as $key => $value) {
            $dataCheckArr[] = $key . '=' . $value;
        }
        sort($dataCheckArr);
        $dataCheckString = implode("\n", $dataCheckArr);

        $secretKey = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($hash, $checkHash);
    }
}
