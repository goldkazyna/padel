<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        
        // Ближайшие открытые турниры (личные/приватные не показываем)
        $upcomingTournaments = Tournament::where('status', 'open')
            ->where('start_date', '>=', now())
            ->whereNull('creator_id')
            ->orderBy('start_date', 'asc')
            ->limit(5)
            ->get();
        
        // Турниры в которых участвует игрок
        $myTournaments = Tournament::whereHas('participants', function($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->orderBy('created_at', 'asc')
            ->limit(5)
            ->get();
        
        return view('dashboard.player', compact('upcomingTournaments', 'myTournaments'));
    }

    public function admin()
    {
        return view('dashboard.admin');
    }

    public function club()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            $club = \App\Models\Club::first();
        } elseif ($user->isClubModerator()) {
            $club = $user->moderatorClubs()->first();
        } else {
            $club = $user->adminClubs()->first();
        }
        return view('dashboard.club', compact('club'));
    }
}