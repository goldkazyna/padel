<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Очищаем телефон
        $phone = preg_replace('/\D/', '', $request->phone);
        if (str_starts_with($phone, '8') && strlen($phone) === 11) {
            $phone = '7' . substr($phone, 1);
        }

        // Проверяем уникальность телефона
        if (User::where('phone', $phone)->exists()) {
            return back()->withErrors(['phone' => 'Этот телефон уже зарегистрирован.'])->withInput();
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'name' => $request->first_name . ' ' . $request->last_name,
            'phone' => $phone,
            'email' => $request->email ?? $phone . '@padel.local',
            'password' => Hash::make($request->password),
            'role' => 'player',
            'rating' => 1000,
            'level' => 1.0,
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard'));
    }
}