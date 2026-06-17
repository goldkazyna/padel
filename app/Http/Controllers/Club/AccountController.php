<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function index()
    {
        return view('club.settings.index', [
            'user' => auth()->user(),
            'club' => $this->getClub(),
        ]);
    }

    /**
     * Настройки самого клуба (например, кнопка «Записаться без оплаты»).
     */
    public function updateClubSettings(Request $request)
    {
        $club = $this->getClub();
        if (!$club) {
            return back()->with('error', 'Клуб не найден');
        }

        $club->update([
            'allow_booking_without_payment' => $request->boolean('allow_booking_without_payment'),
        ]);

        return back()->with('success', 'Настройки клуба обновлены');
    }

    /** Клуб текущего пользователя (админ/модератор). Супер-админ — null. */
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            return null;
        }
        if ($user->isClubModerator()) {
            return $user->moderatorClubs()->first();
        }
        return $user->adminClubs()->first();
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ], [
            'name.required' => 'Введите имя',
            'name.max' => 'Имя слишком длинное (максимум :max символов)',
        ]);
        auth()->user()->update($validated);

        return back()->with('success', 'Профиль обновлён');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(6)],
        ], [
            'current_password.required' => 'Введите текущий пароль',
            'current_password.current_password' => 'Текущий пароль указан неверно',
            'password.required' => 'Введите новый пароль',
            'password.confirmed' => 'Новый пароль и подтверждение не совпадают',
            'password.min' => 'Новый пароль должен быть не менее :min символов',
        ]);

        // password имеет cast 'hashed' — хешируется автоматически.
        auth()->user()->update(['password' => $request->input('password')]);

        return back()->with('success', 'Пароль изменён');
    }
}
