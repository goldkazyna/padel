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

    /**
     * Актуальные карты клиента по телефону — для окна брони корта.
     * GET /club/cards/for-client?phone=...
     */
    public function forClient(Request $request)
    {
        $club = $this->getClub();
        $debug = $request->boolean('debug');
        if (!$club) {
            return response()->json($debug ? ['cards' => [], 'debug' => ['club' => null]] : ['cards' => []]);
        }

        $digits = preg_replace('/\D/', '', (string) $request->get('phone'));
        if (strlen($digits) < 5) return response()->json(['cards' => []]);
        $last10 = substr($digits, -10);

        $client = ClubClient::where('club_id', $club->id)
            ->where(fn($q) => $q->where('phone', $digits)->orWhere('phone', 'like', '%' . $last10))
            ->first();

        if ($debug) {
            $anyClient = ClubClient::where(fn($q) => $q->where('phone', $digits)->orWhere('phone', 'like', '%' . $last10))->first();
            $allCardsForAnyClient = $anyClient
                ? ClubCard::where('club_client_id', $anyClient->id)->with('type')->get()
                : collect();
            return response()->json(['cards' => [], 'debug' => [
                'resolved_club_id' => $club->id,
                'resolved_club_name' => $club->name,
                'is_super_admin' => (bool) auth()->user()?->isSuperAdmin(),
                'digits' => $digits,
                'client_in_resolved_club' => $client?->only(['id', 'name', 'club_id']),
                'any_client_any_club' => $anyClient?->only(['id', 'name', 'club_id']),
                'cards_for_any_client' => $allCardsForAnyClient->map(fn($c) => [
                    'id' => $c->id, 'code' => $c->code, 'club_id' => $c->club_id,
                    'status' => $c->status, 'balance' => $c->balance,
                    'expires_at' => $c->expires_at?->toDateString(),
                    'is_actual' => $c->isActual(),
                ])->values(),
                // Все карты клуба — чтобы увидеть, на какой client_id/телефон висит P000001.
                'all_club_cards' => ClubCard::where('club_id', $club->id)->with('client')->get()
                    ->map(fn($c) => [
                        'code' => $c->code,
                        'client_id' => $c->club_client_id,
                        'client_name' => $c->client?->name,
                        'client_phone' => $c->client?->phone,
                    ])->values(),
                // Все записи клиентов клуба с этим телефоном (дубликаты).
                'clients_with_phone' => ClubClient::where('club_id', $club->id)
                    ->where(fn($q) => $q->where('phone', $digits)->orWhere('phone', 'like', '%' . $last10))
                    ->get(['id', 'name', 'phone']),
            ]]);
        }

        if (!$client) return response()->json(['cards' => []]);

        $cards = ClubCard::where('club_client_id', $client->id)
            ->where('club_id', $club->id)
            ->with('type')
            ->orderByDesc('created_at')
            ->get()
            ->filter->isActual()
            ->map(fn($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'type_name' => $c->type?->name,
                'kind' => $c->type?->kind,
                'is_counter' => $c->isCounter(),
                'is_discount' => (bool) $c->type?->isDiscount(),
                'balance' => $c->balance,
                'discount_percent' => $c->type?->discount_percent,
                'price' => $c->type?->price,          // стоимость карты
                'nominal' => $c->type?->nominal,      // число занятий (для цены за визит)
            ])
            ->values();

        return response()->json(['cards' => $cards]);
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
