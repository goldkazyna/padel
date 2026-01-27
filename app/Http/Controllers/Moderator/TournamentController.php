<?php

namespace App\Http\Controllers\Moderator;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    private function getClub()
    {
        return auth()->user()->moderatorClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();
        
        if (!$club) {
            abort(403, 'Вы не привязаны к клубу');
        }

        // Только открытые турниры
        $tournaments = Tournament::where('club_id', $club->id)
            ->where('status', 'open')
            ->orderBy('start_date', 'asc')
            ->get();

        return view('moderator.tournaments.index', compact('tournaments', 'club'));
    }

    public function show(Tournament $tournament)
    {
        $club = $this->getClub();
        
        if (!$club || $tournament->club_id != $club->id) {
            abort(403);
        }

        if ($tournament->status !== 'open') {
            abort(403, 'Турнир недоступен для модерации');
        }

        $tournament->load(['club', 'participants']);
        
        return view('moderator.tournaments.show', compact('tournament'));
    }

    public function approveParticipant(Tournament $tournament, $userId)
    {
        $club = $this->getClub();
        
        if (!$club || $tournament->club_id != $club->id || $tournament->status !== 'open') {
            abort(403);
        }

        $tournament->participants()->updateExistingPivot($userId, ['status' => 'registered']);

        return back()->with('success', 'Участник одобрен');
    }

    public function rejectParticipant(Tournament $tournament, $userId)
    {
        $club = $this->getClub();
        
        if (!$club || $tournament->club_id != $club->id || $tournament->status !== 'open') {
            abort(403);
        }

        $tournament->participants()->detach($userId);

        return back()->with('success', 'Заявка отклонена');
    }

    public function removeParticipant(Tournament $tournament, User $user)
    {
        $club = $this->getClub();
        
        if (!$club || $tournament->club_id != $club->id || $tournament->status !== 'open') {
            abort(403);
        }

        $tournament->participants()->detach($user->id);

        return back()->with('success', 'Участник удалён');
    }
}