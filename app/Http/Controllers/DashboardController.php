<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        if ($user->isClubAdmin()) {
            return redirect()->route('club.dashboard');
        }

        return view('dashboard.player');
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