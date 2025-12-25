<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    // Список турниров для игроков
    public function index()
    {
        $tournaments = Tournament::with('club')
            ->where('status', '!=', 'draft')
            ->orderBy('start_date')
            ->get();

        return view('tournaments.index', compact('tournaments'));
    }

    // Страница турнира
    public function show(Tournament $tournament)
    {
        $tournament->load(['club', 'participants']);
        
        return view('tournaments.show', compact('tournament'));
    }

    // Регистрация на турнир
    public function register(Tournament $tournament)
    {
        $user = auth()->user();

        if (!$tournament->canRegister($user)) {
            return back()->with('error', 'Вы не можете зарегистрироваться на этот турнир');
        }

        $tournament->participants()->attach($user->id, ['status' => 'registered']);

        return back()->with('success', 'Вы успешно зарегистрировались на турнир!');
    }

    // Отмена регистрации
    public function cancel(Tournament $tournament)
    {
        $user = auth()->user();

        if (!$tournament->isRegistered($user)) {
            return back()->with('error', 'Вы не зарегистрированы на этот турнир');
        }

        // Можно отменить только пока турнир не начался
        if ($tournament->status === 'in_progress' || $tournament->status === 'completed') {
            return back()->with('error', 'Нельзя отменить регистрацию после начала турнира');
        }

        $tournament->participants()->detach($user->id);

        return back()->with('success', 'Регистрация отменена');
    }
}