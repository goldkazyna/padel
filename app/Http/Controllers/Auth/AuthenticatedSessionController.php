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
        $loginType = $request->input('login_type', 'phone');
        
        if ($loginType === 'email') {
            // Вход по email
            $request->validate([
                'email' => ['required', 'string', 'email'],
                'password' => ['required', 'string'],
            ]);

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

            if (Auth::attempt(['phone' => $phone, 'password' => $request->password], $request->boolean('remember'))) {
                $request->session()->regenerate();
                return redirect()->intended(route('dashboard'));
            }

            return back()->withErrors([
                'phone' => 'Неверный телефон или пароль.',
            ])->onlyInput('phone');
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}