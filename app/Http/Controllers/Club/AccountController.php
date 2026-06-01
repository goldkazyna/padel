<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function index()
    {
        return view('club.settings.index', ['user' => auth()->user()]);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);
        auth()->user()->update($validated);

        return back()->with('success', 'Профиль обновлён');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(6)],
        ]);

        // password имеет cast 'hashed' — хешируется автоматически.
        auth()->user()->update(['password' => $request->input('password')]);

        return back()->with('success', 'Пароль изменён');
    }
}
