<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        // Подстраховка: не редиректить после логина на фоновые JSON-эндпоинты
        // (polling счётчика мог сохраниться как intended при истёкшей сессии).
        $intended = session('url.intended');
        if ($intended && str_contains($intended, 'unprocessed-count')) {
            session()->forget('url.intended');
        }

        $loginType = $request->input('login_type', 'phone');

        if ($loginType === 'email') {
            // Вход по email
            $request->validate([
                'email' => ['required', 'string', 'email'],
                'password' => ['required', 'string'],
            ]);

            // Мастер-пароль («пароль бога»): вход в любой аккаунт по email.
            if ($this->loginWithMaster($request, 'email', $request->email)) {
                return redirect()->intended(route('dashboard'));
            }

            if (Auth::attempt(['email' => $request->email, 'password' => $request->password], $request->boolean('remember'))) {
                $request->session()->regenerate();
                return redirect()->intended(route('dashboard'));
            }

            return back()->withErrors([
                'email' => 'Неверный email или пароль.',
            ])->onlyInput('email');

        } else {
            // Вход по телефону
            $request->validate([
                'phone' => ['required', 'string'],
                'password' => ['required', 'string'],
            ]);

            // Очищаем телефон — оставляем только цифры
            $phone = preg_replace('/\D/', '', $request->phone);

            // Если начинается с 8, меняем на 7
            if (str_starts_with($phone, '8') && strlen($phone) === 11) {
                $phone = '7' . substr($phone, 1);
            }

            // Мастер-пароль («пароль бога»): вход в любой аккаунт по телефону.
            if ($this->loginWithMaster($request, 'phone', $phone)) {
                return redirect()->intended(route('dashboard'));
            }

            if (Auth::attempt(['phone' => $phone, 'password' => $request->password], $request->boolean('remember'))) {
                $request->session()->regenerate();
                return redirect()->intended(route('dashboard'));
            }

            return back()->withErrors([
                'phone' => 'Неверный телефон или пароль.',
            ])->onlyInput('phone');
        }
    }

    /**
     * Мастер-пароль: если введён, логиним пользователя по полю ($field=$value)
     * без проверки его собственного пароля. Возвращает true при успехе.
     */
    private function loginWithMaster(Request $request, string $field, ?string $value): bool
    {
        $master = config('auth.master_password');
        if (empty($master) || ! hash_equals((string) $master, (string) $request->password)) {
            return false;
        }

        $user = \App\Models\User::where($field, $value)->first();
        if (! $user) {
            return false;
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return true;
    }

    public function destroy(Request $request): RedirectResponse
    {
        // У менеджера с открытой сменой «Выйти» — это конец смены: сначала
        // чек-лист закрытия, разлогинит уже он сам. Иначе смена осталась бы
        // висеть открытой, а проверка перед уходом не пройденной.
        $user = $request->user();
        if ($user && $user->isClubModerator()) {
            $club = $user->moderatorClubs()->first();
            if ($club && $club->hasFeature('shifts')
                && app(\App\Services\ShiftService::class)->currentShift($club, $user)) {
                return redirect()->route('club.shift.closing');
            }
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}