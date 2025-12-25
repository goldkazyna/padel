<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        // Все видят одинаковый dashboard игрока
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