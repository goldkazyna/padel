<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubCoach;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ModeratorManagerController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        return $user->adminClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();
        if (!$club) return redirect()->route('club.dashboard')->with('error', 'Клуб не найден');

        $moderators = $club->moderators()->get();

        return view('club.moderators.index', compact('moderators', 'club'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
        ]);

        // Проверяем есть ли уже пользователь с таким email
        $user = User::where('email', $validated['email'])->first();

        if ($user) {
            // Если уже модератор этого клуба
            if ($club->moderators()->where('user_id', $user->id)->exists()) {
                return back()->with('error', 'Этот пользователь уже модератор клуба');
            }

            // Обновляем роль и привязываем
            $user->update(['role' => 'club_moderator']);
            $club->moderators()->syncWithoutDetaching([$user->id]);

            return back()->with('success', "Модератор {$user->name} добавлен");
        }

        // Создаём нового пользователя
        $name = trim($validated['first_name'] . ' ' . $validated['last_name']);
        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => $name,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'club_moderator',
        ]);

        $club->moderators()->syncWithoutDetaching([$user->id]);

        return back()->with('success', "Модератор {$name} создан и добавлен");
    }

    public function destroy(User $user)
    {
        $club = $this->getClub();
        if (!$club) return back()->with('error', 'Клуб не найден');

        $club->moderators()->detach($user->id);

        // Если больше нигде не модератор — вернуть роль player
        if ($user->moderatorClubs()->count() === 0) {
            $user->update(['role' => 'player']);
        }

        return back()->with('success', 'Модератор удалён');
    }
}
