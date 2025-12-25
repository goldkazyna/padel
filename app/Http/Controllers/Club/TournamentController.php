<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Club;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    // Получить клуб текущего админа
    private function getClub()
    {
        $user = auth()->user();
        
        if ($user->isSuperAdmin()) {
            return null; // Супер-админ видит все
        }
        
        return $user->adminClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();
        
        if ($club) {
            $tournaments = Tournament::where('club_id', $club->id)
                ->orderBy('start_date', 'desc')
                ->get();
        } else {
            $tournaments = Tournament::with('club')
                ->orderBy('start_date', 'desc')
                ->get();
        }

        return view('club.tournaments.index', compact('tournaments', 'club'));
    }

    public function create()
    {
        $club = $this->getClub();
        $clubs = auth()->user()->isSuperAdmin() ? Club::active()->get() : collect([$club]);
        
        return view('club.tournaments.create', compact('clubs', 'club'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        
        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date|after:now',
            'registration_deadline' => 'required|date|before:start_date',
            'min_level' => 'required|numeric|min:1|max:5.75',
            'max_level' => 'required|numeric|min:1|max:5.75|gte:min_level',
            'max_participants' => 'required|integer|min:2|max:128',
            'price' => 'nullable|numeric|min:0',
            'status' => 'required|in:draft,open',
        ]);

        // Проверяем доступ к клубу
        if ($club && $validated['club_id'] != $club->id) {
            abort(403);
        }

        Tournament::create($validated);

        return redirect()->route('club.tournaments.index')->with('success', 'Турнир создан!');
    }

    public function show(Tournament $tournament)
    {
        $club = $this->getClub();
        
        // Проверяем доступ
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }

        $tournament->load(['club', 'participants']);

        return view('club.tournaments.show', compact('tournament'));
    }

    public function edit(Tournament $tournament)
    {
        $club = $this->getClub();
        
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }

        $clubs = auth()->user()->isSuperAdmin() ? Club::active()->get() : collect([$club]);

        return view('club.tournaments.edit', compact('tournament', 'clubs', 'club'));
    }

    public function update(Request $request, Tournament $tournament)
    {
        $club = $this->getClub();
        
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'registration_deadline' => 'required|date|before:start_date',
            'min_level' => 'required|numeric|min:1|max:5.75',
            'max_level' => 'required|numeric|min:1|max:5.75|gte:min_level',
            'max_participants' => 'required|integer|min:2|max:128',
            'price' => 'nullable|numeric|min:0',
            'status' => 'required|in:draft,open,closed,in_progress,completed,cancelled',
        ]);

        $tournament->update($validated);

        return redirect()->route('club.tournaments.index')->with('success', 'Турнир обновлён!');
    }

    public function destroy(Tournament $tournament)
    {
        $club = $this->getClub();
        
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }

        // Можно удалить только черновик
        if ($tournament->status !== 'draft') {
            return back()->with('error', 'Можно удалить только черновик');
        }

        $tournament->delete();

        return redirect()->route('club.tournaments.index')->with('success', 'Турнир удалён!');
    }

    // Удалить участника
    public function removeParticipant(Tournament $tournament, $userId)
    {
        $club = $this->getClub();
        
        if ($club && $tournament->club_id != $club->id) {
            abort(403);
        }

        $tournament->participants()->detach($userId);

        return back()->with('success', 'Участник удалён');
    }
}