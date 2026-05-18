<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClubClient;
use App\Models\CourtBooking;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Отдельная страница: все брони клиента с фильтром периода.
     * GET /club/clients/{client}/bookings?period=...&from=...&to=...
     */
    public function bookings(Request $request, ClubClient $client)
    {
        $club = $this->getClub();
        if (!$club || $client->club_id !== $club->id) abort(403);

        $period = $request->get('period', 'future');
        [$from, $to] = $this->resolveBookingPeriod($period, $request);

        $bookings = collect();
        if ($client->phone) {
            // У клиента телефон может храниться с/без ведущей 7, у бронирования —
            // в любом виде. Собираем все варианты для надёжного матчинга.
            $phoneVariants = $this->phoneVariants($client->phone);

            $bq = CourtBooking::with('court')
                ->whereHas('court', fn($q) => $q->where('club_id', $club->id))
                ->whereIn('client_phone', $phoneVariants)
                ->where('status', 'confirmed')
                ->orderBy('date', 'desc')
                ->orderBy('start_time', 'desc');
            if ($from) $bq->whereDate('date', '>=', $from->format('Y-m-d'));
            if ($to)   $bq->whereDate('date', '<=', $to->format('Y-m-d'));
            $bookings = $bq->get();
        }

        $stats = ['count' => 0, 'hours' => 0, 'amount' => 0, 'paid' => 0, 'unpaid' => 0];
        foreach ($bookings as $b) {
            $stats['count']++;
            $startMin = Carbon::parse($b->start_time)->hour * 60 + Carbon::parse($b->start_time)->minute;
            $endMin = Carbon::parse($b->end_time)->hour * 60 + Carbon::parse($b->end_time)->minute;
            if ($endMin <= $startMin) $endMin += 24 * 60;
            $stats['hours'] += ($endMin - $startMin) / 60;
            $stats['amount'] += (float) $b->price;
            if ($b->is_paid) $stats['paid']++; else $stats['unpaid']++;
        }

        return view('club.clients.bookings', [
            'club' => $club,
            'client' => $client,
            'bookings' => $bookings,
            'stats' => $stats,
            'period' => $period,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Возвращает все возможные варианты записи телефона:
     * - как есть (нормализованный, только цифры)
     * - с ведущей 7 (если 10 цифр)
     * - без ведущей 7 (если 11 цифр и начинается с 7)
     * Нужно для матчинга, потому что в БД телефон у клиента и у брони мог
     * быть сохранён в разном виде.
     */
    private function phoneVariants(?string $phone): array
    {
        if (!$phone) return [];
        $digits = preg_replace('/\D/', '', $phone);
        $variants = [$digits];
        if (strlen($digits) === 10) {
            $variants[] = '7' . $digits;
        } elseif (strlen($digits) === 11 && $digits[0] === '7') {
            $variants[] = substr($digits, 1);
        }
        return array_values(array_unique($variants));
    }

    /**
     * Возвращает [from, to] (Carbon|null) для фильтра бронирований клиента.
     */
    private function resolveBookingPeriod(string $period, Request $request): array
    {
        $now = Carbon::now();
        return match ($period) {
            'future' => [$now->copy()->startOfDay(), null],
            'all' => [null, null],
            'current_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'previous_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [$now->copy()->subMonthsNoOverflow(2)->startOfMonth(), $now->copy()->endOfMonth()],
            'custom' => [
                $request->filled('from') ? Carbon::parse($request->get('from'))->startOfDay() : null,
                $request->filled('to') ? Carbon::parse($request->get('to'))->endOfDay() : null,
            ],
            default => [$now->copy()->startOfDay(), null],
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

    /**
     * Выгрузка всех клиентов клуба в CSV (открывается в Excel).
     * GET /club/clients/export
     */
    public function export(): StreamedResponse
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $clients = ClubClient::where('club_id', $club->id)
            ->orderBy('name')
            ->get();

        $fileName = 'clients_' . $club->id . '_' . Carbon::now()->format('Y-m-d') . '.csv';

        $callback = function () use ($clients) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM чтобы Excel корректно отображал кириллицу
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Имя', 'Телефон', 'Пол', 'Дата рождения', 'Заметка', 'Добавлен',
            ], ';');

            foreach ($clients as $c) {
                $gender = match ($c->gender) {
                    'male' => 'Мужской',
                    'female' => 'Женский',
                    default => '',
                };
                fputcsv($out, [
                    $c->name,
                    $c->phone ? '+' . $c->phone : '',
                    $gender,
                    $c->birth_date ? $c->birth_date->format('d.m.Y') : '',
                    $c->note ?? '',
                    $c->created_at?->format('d.m.Y') ?? '',
                ], ';');
            }
            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
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
