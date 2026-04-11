<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubClient;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $query = ClubClient::where('club_id', $club->id)->orderBy('name');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $clients = $query->paginate(20)->withQueryString();
        $totalCount = ClubClient::where('club_id', $club->id)->count();
        $selectedId = $request->get('selected');

        $selectedClient = $selectedId
            ? ClubClient::where('club_id', $club->id)->find($selectedId)
            : $clients->first();

        return view('club.clients.index', compact('clients', 'totalCount', 'selectedClient'));
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'note' => 'nullable|string|max:1000',
            'gender' => 'nullable|in:male,female',
            'birth_date' => 'nullable|date',
        ]);

        $client = ClubClient::create([...$validated, 'club_id' => $club->id]);

        return redirect()->route('club.clients.index', ['selected' => $client->id])
            ->with('success', 'Клиент добавлен');
    }

    public function update(Request $request, ClubClient $client)
    {
        $club = $this->getClub();
        if (!$club || $client->club_id !== $club->id) abort(403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'note' => 'nullable|string|max:1000',
            'gender' => 'nullable|in:male,female',
            'birth_date' => 'nullable|date',
        ]);

        $client->update($validated);

        return redirect()->route('club.clients.index', ['selected' => $client->id])
            ->with('success', 'Клиент обновлён');
    }

    public function destroy(ClubClient $client)
    {
        $club = $this->getClub();
        if (!$club || $client->club_id !== $club->id) abort(403);

        $client->delete();

        return redirect()->route('club.clients.index')
            ->with('success', 'Клиент удалён');
    }
}
