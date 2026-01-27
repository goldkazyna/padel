<?php

namespace App\Http\Controllers\Moderator;

use App\Http\Controllers\Controller;
use App\Models\Tournament;

class DashboardController extends Controller
{
	public function index()
	{
		$club = auth()->user()->moderatorClubs()->first();
		
		if (!$club) {
			abort(403, 'Вы не привязаны к клубу');
		}

		$openTournaments = Tournament::where('club_id', $club->id)
			->where('status', 'open')
			->count();

		$pendingParticipants = Tournament::where('club_id', $club->id)
			->where('status', 'open')
			->withCount(['participants' => fn($q) => $q->where('tournament_participants.status', 'pending')])
			->get()
			->sum('participants_count');

		return view('moderator.dashboard', compact('club', 'openTournaments', 'pendingParticipants'));
	}
}