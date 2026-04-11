<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
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

    public function search(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return response()->json([]);

        $q = $request->get('q', '');
        $field = $request->get('field', 'name');

        $query = ClubClient::where('club_id', $club->id);

        if ($field === 'phone') {
            $query->where('phone', 'like', "%{$q}%");
        } else {
            $query->where('name', 'like', "%{$q}%");
        }

        return response()->json(
            $query->orderBy('name')->limit(10)->get(['id', 'name', 'phone'])
        );
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

        if (!empty($validated['phone'])) {
            $validated['phone'] = preg_replace('/\D/', '', $validated['phone']);
        }

        $client = ClubClient::create([...$validated, 'club_id' => $club->id]);

        $details = $client->name;
        if ($client->phone) $details .= ", тел. {$client->phone}";
        if ($client->gender) $details .= ", " . ($client->gender === 'male' ? 'М' : 'Ж');
        if ($client->birth_date) $details .= ", д.р. " . $client->birth_date->format('d.m.Y');
        ActivityLog::log('created', 'ClubClient', $client->id, "Добавлен клиент: {$details}", $validated);

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

        if (!empty($validated['phone'])) {
            $validated['phone'] = preg_replace('/\D/', '', $validated['phone']);
        }

        $oldValues = $client->only(array_keys($validated));
        $client->update($validated);
        $changes = $client->getChanges();
        unset($changes['updated_at']);

        if ($changes) {
            $changedFields = [];
            $fieldNames = ['name' => 'имя', 'phone' => 'телефон', 'gender' => 'пол', 'birth_date' => 'дата рождения', 'note' => 'заметка'];
            foreach ($changes as $field => $newVal) {
                $label = $fieldNames[$field] ?? $field;
                $oldVal = $oldValues[$field] ?? '—';
                if ($field === 'gender') {
                    $oldVal = match($oldVal) { 'male' => 'М', 'female' => 'Ж', default => '—' };
                    $newVal = match($newVal) { 'male' => 'М', 'female' => 'Ж', default => '—' };
                }
                $changedFields[] = "{$label}: {$oldVal} → {$newVal}";
            }
            ActivityLog::log('updated', 'ClubClient', $client->id, "Редактирование клиента {$client->name}: " . implode(', ', $changedFields), ['old' => $oldValues, 'new' => $changes]);
        }

        return redirect()->route('club.clients.index', ['selected' => $client->id])
            ->with('success', 'Клиент обновлён');
    }

    public function destroy(ClubClient $client)
    {
        $club = $this->getClub();
        if (!$club || $client->club_id !== $club->id) abort(403);

        $clientName = $client->name;
        $clientPhone = $client->phone;
        $clientData = $client->toArray();
        $client->delete();

        $details = "Удалён клиент: {$clientName}";
        if ($clientPhone) $details .= ", тел. {$clientPhone}";
        ActivityLog::log('deleted', 'ClubClient', null, $details, $clientData);

        return redirect()->route('club.clients.index')
            ->with('success', 'Клиент удалён');
    }
}
