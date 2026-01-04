<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        
        // Ближайшие открытые турниры
        $upcomingTournaments = Tournament::where('status', 'open')
            ->where('start_date', '>=', now())
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
        return view('dashboard.club');
    }
}