<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
	public function show()
	{
		$user = auth()->user();
		
		// Статистика матчей
		$stats = $user->getAllMatchesStats();
		
		// История рейтинга
		$ratingHistory = $user->ratingHistory()->take(20)->get();
		
		// Турниры
		$tournamentStats = $user->getTournamentStats();
		
		// Лучший партнёр
		$bestPartner = $user->getBestPartner();
		
		// Серия
		$streak = $user->getCurrentStreak();
		
		// Тренд
		$trend = $user->getRatingTrend();
		
		// Достижения
		$achievements = $user->getAchievements();
		
		// Последние матчи
		$matchHistory = array_slice($user->getMatchHistory(), 0, 5);
		
		// Место в рейтинге
		$rank = \App\Models\User::human()
			->where('rating', '>', $user->rating)
			->count() + 1;
		
		$totalPlayers = \App\Models\User::human()->count();

		return view('profile.show', compact(
			'user', 'stats', 'ratingHistory', 'tournamentStats',
			'bestPartner', 'streak', 'trend', 'achievements',
			'matchHistory', 'rank', 'totalPlayers'
		));
	}

    public function edit()
    {
        return view('profile.edit', ['user' => Auth::user()]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
        ]);

        $user = Auth::user();
        $user->update($validated);

        return redirect()->route('profile.show')->with('success', 'Профиль обновлён!');
    }
}