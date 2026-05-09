<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClubClient;
use App\Models\CourtBooking;
use Carbon\Carbon;
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

        // Бронирования выбранного клиента — для сводки в правой колонке
        $bookingPeriod = $request->get('booking_period', 'current_month');
        $clientBookings = collect();
        $bookingStats = ['count' => 0, 'hours' => 0, 'amount' => 0];

        if ($selectedClient && $selectedClient->phone) {
            [$from, $to] = $this->resolveBookingPeriod($bookingPeriod, $request);
            $bookingQuery = CourtBooking::with('court')
                ->whereHas('court', fn($q) => $q->where('club_id', $club->id))
                ->where('client_phone', $selectedClient->phone)
                ->where('status', 'confirmed')
                ->orderBy('date', 'desc')
                ->orderBy('start_time', 'desc');
            if ($from) $bookingQuery->whereDate('date', '>=', $from);
            if ($to) $bookingQuery->whereDate('date', '<=', $to);

            $clientBookings = $bookingQuery->get();

            foreach ($clientBookings as $b) {
                $bookingStats['count']++;
                $startMin = Carbon::parse($b->start_time)->hour * 60 + Carbon::parse($b->start_time)->minute;
                $endMin = Carbon::parse($b->end_time)->hour * 60 + Carbon::parse($b->end_time)->minute;
                if ($endMin <= $startMin) $endMin += 24 * 60;
                $bookingStats['hours'] += ($endMin - $startMin) / 60;
                $bookingStats['amount'] += (float) $b->price;
            }
        }

        return view('club.clients.index', compact(
            'clients', 'totalCount', 'selectedClient',
            'clientBookings', 'bookingStats', 'bookingPeriod'
        ));
    }

    /**
     * Возвращает [from, to] (Carbon|null) для фильтра бронирований клиента.
     */
    private function resolveBookingPeriod(string $period, Request $request): array
    {
        $now = Carbon::now();
        return match ($period) {
            'current_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'previous_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [$now->copy()->subMonthsNoOverflow(2)->startOfMonth(), $now->copy()->endOfMonth()],
            'all' => [null, null],
            'custom' => [
                $request->filled('booking_from') ? Carbon::parse($request->get('booking_from'))->startOfDay() : null,
                $request->filled('booking_to') ? Carbon::parse($request->get('booking_to'))->endOfDay() : null,
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    public function search(Request $request)
    {
        $club = $this->getClub();
        if (!$club) return response()->json([]);

        $q = $request->get('q', '');
        $field = $request->get('field', 'name');

        $query = ClubClient::where('club_id', $club->id);

        if ($field === 'phone') {
            $q = preg_replace('/\D/', '', $q);
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
