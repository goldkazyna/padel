<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TournamentController extends Controller
{
    // Список турниров для игроков
    public function index()
    {
        $tournaments = Tournament::with('club')
            ->where('status', '!=', 'draft')
            ->whereNull('creator_id') // личные/приватные турниры не показываем
            ->orderBy('created_at', 'desc')
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

        // Атомарная проверка + запись (защита от race condition)
        $registered = DB::transaction(function () use ($tournament, $user) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            if (!$tournament->canRegister($user)) {
                return false;
            }

            $tournament->participants()->attach($user->id, ['status' => 'pending']);
            return true;
        });

        if (!$registered) {
            return back()->with('error', 'Вы не можете зарегистрироваться на этот турнир');
        }

        return back()->with('success', 'Заявка отправлена! Ожидайте подтверждения от организатора.');
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