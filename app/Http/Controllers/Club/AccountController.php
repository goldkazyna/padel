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

        // Часы отмены брони: 0..168 (0 — без ограничения).
        $cancelHours = (int) $request->input('booking_cancel_hours', 2);
        $cancelHours = max(0, min(168, $cancelHours));

        $club->update([
            'allow_booking_without_payment' => $request->boolean('allow_booking_without_payment'),
            'auto_conduct_group_sessions' => $request->boolean('auto_conduct_group_sessions'),
            'booking_cancel_hours' => $cancelHours,
            'card_bg_color' => $this->hexOrNull($request->input('card_bg_color')),
            'card_accent_color' => $this->hexOrNull($request->input('card_accent_color')),
            'card_progress_color' => $this->hexOrNull($request->input('card_progress_color')),
        ]);

        return back()->with('success', 'Настройки клуба обновлены');
    }

    /** #RRGGBB → нормализованный HEX или null. */
    private function hexOrNull($value): ?string
    {
        $v = trim((string) $value);
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : null;
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
