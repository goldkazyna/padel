<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    public function index()
    {
        $players = User::where('role', 'player')
                       ->orWhere('role', 'club_admin')
                       ->orderBy('rating', 'desc')
                       ->get();

        return view('rating.index', compact('players'));
    }
}