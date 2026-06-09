<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Services\ClubCardService;
use Illuminate\Http\Request;

class ClubCardController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    /** Выпустить карту клиенту. */
    public function store(Request $request, ClubCardService $service)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $data = $request->validate([
            'club_client_id' => 'required|integer',
            'club_card_type_id' => 'required|integer',
            'balance' => 'nullable|integer|min:0|max:100000',
            'expires_at' => 'nullable|date|after:today',
        ]);

        $client = ClubClient::where('club_id', $club->id)->findOrFail($data['club_client_id']);
        $type = ClubCardType::where('club_id', $club->id)->findOrFail($data['club_card_type_id']);

        $service->issue(
            $client,
            $type,
            $data['balance'] ?? null,
            $data['expires_at'] ?? null
        );

        return back()->with('success', 'Карта «' . $type->name . '» привязана клиенту');
    }

    /** Отвязать (удалить) карту клиента. */
    public function destroy(ClubCard $card)
    {
        $club = $this->getClub();
        if (!$club || $card->club_id !== $club->id) abort(403);

        $card->delete();

        return back()->with('success', 'Карта отвязана');
    }
}
