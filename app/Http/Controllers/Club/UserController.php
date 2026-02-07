<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'player')->orderBy('name');

        // Поиск
        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate(20)->withQueryString();

        // Статистика по уровням
        $levelStats = User::where('role', 'player')
            ->selectRaw("
                SUM(CASE WHEN level >= 1 AND level <= 1.75 THEN 1 ELSE 0 END) as level_1,
                SUM(CASE WHEN level >= 2 AND level <= 2.75 THEN 1 ELSE 0 END) as level_2,
                SUM(CASE WHEN level >= 3 AND level <= 3.75 THEN 1 ELSE 0 END) as level_3,
                SUM(CASE WHEN level >= 4 AND level <= 4.75 THEN 1 ELSE 0 END) as level_4,
                SUM(CASE WHEN level >= 5 AND level <= 5.75 THEN 1 ELSE 0 END) as level_5
            ")
            ->first();

        return view('club.users.index', compact('users', 'levelStats'));
    }

    public function update(Request $request, User $user)
    {
        if ((float) $user->level != 1.0) {
            return back()->with('error', 'Можно менять уровень только новичкам (уровень 1.0)');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'level' => 'required|numeric|min:1|max:5.75',
        ]);

        $newLevel = (float) $validated['level'];
        $validated['rating'] = (int) ($newLevel * 1000 + 125);

        $user->update($validated);

        return back()->with('success', 'Пользователь обновлён!');
    }
}