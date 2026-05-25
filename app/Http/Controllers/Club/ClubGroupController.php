<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use Illuminate\Http\Request;

class ClubGroupController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $groups = ClubGroup::where('club_id', $club->id)
            ->withCount(['members as active_members_count' => fn($q) => $q->where('status', 'active')])
            ->orderByRaw("status = 'archived'")
            ->orderBy('name')
            ->get();

        $coaches = $club->clubCoaches()->with('user')->get();

        return view('club.groups.index', compact('groups', 'club', 'coaches'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'coach_id' => 'nullable|exists:users,id',
            'price_per_session' => 'nullable|numeric|min:0',
            'capacity' => 'nullable|integer|min:1|max:100',
            'note' => 'nullable|string|max:1000',
        ]);
        $validated['club_id'] = $club->id;
        $validated['price_per_session'] = $validated['price_per_session'] ?? 0;

        $group = ClubGroup::create($validated);
        \App\Models\ActivityLog::log('created', 'ClubGroup', $group->id, "Группа создана: {$group->name}", clubId: $club->id);

        return redirect()->route('club.groups.show', $group)->with('success', 'Группа создана');
    }

    public function show(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $group->load(['coach', 'members.client', 'members.enrollments']);
        $sessions = $group->sessions()->with('court')->orderByDesc('date')->orderByDesc('start_time')->get();
        $coaches = $club->clubCoaches()->with('user')->get();
        $clients = ClubClient::where('club_id', $club->id)->orderBy('name')->get();

        return view('club.groups.show', compact('group', 'club', 'sessions', 'coaches', 'clients'));
    }

    public function update(Request $request, ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'coach_id' => 'nullable|exists:users,id',
            'price_per_session' => 'nullable|numeric|min:0',
            'capacity' => 'nullable|integer|min:1|max:100',
            'note' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,archived',
        ]);
        $validated['price_per_session'] = $validated['price_per_session'] ?? 0;

        $group->update($validated);

        return back()->with('success', 'Группа обновлена');
    }
}
