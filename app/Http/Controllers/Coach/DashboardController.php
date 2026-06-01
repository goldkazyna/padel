<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\ClubCoach;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class DashboardController extends Controller
{
    /**
     * Своя карточка тренера + расписание (просмотр) + смена пароля.
     */
    public function index()
    {
        $cc = ClubCoach::where('user_id', auth()->id())
            ->with(['user', 'schedules', 'rates', 'club'])
            ->first();

        return view('coach.schedule', ['cc' => $cc]);
    }

    /**
     * Тренер меняет собственный пароль.
     */
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
