<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    public function index()
    {
        $players = User::human()
                       ->orderBy('rating', 'desc')
                       ->paginate(50);

        return view('rating.index', compact('players'));
    }
}