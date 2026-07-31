<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClubClient;
use App\Models\CourtBooking;
use App\Support\PhoneVisibility;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        // Едино с CourtController/ClubCardController: супер-админ работает с Club::first(),
        // иначе карты клиента в окне брони не находятся (разные клубы у автокомплита и эндпоинта).
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $query = ClubClient::where('club_id', $club->id)
            ->withCount([
                'certificates',
                'certificates as certificates_used_count' => fn($q) => $q->whereNotNull('used_at'),
            ])
            ->orderBy('name');

        if ($search = $request->get('search')) {
            // Для phone сравниваем только цифры из запроса (без +, пробелов, скобок),
            // т.к. в БД phone хранится в нормализованном виде (только цифры).
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%");
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        $clients = $query->paginate(20)->withQueryString();
        $totalCount = ClubClient::where('club_id', $club->id)->count();
        $selectedId = $request->get('selected');

        $selectedClient = $selectedId
            ? ClubClient::where('club_id', $club->id)->find($selectedId)
            : $clients->first();

        // Счётчики сертификатов для выбранного клиента (для кнопки в деталях).
        $selectedClient?->loadCount([
            'certificates',
            'certificates as certificates_used_count' => fn($q) => $q->whereNotNull('used_at'),
        ]);

        $clientGroups = collect();
        $clientCards = collect();
        $clientTrials = collect();
        $cardTypes = collect();
        if ($selectedClient) {
            $clientGroups = \App\Models\ClubGroupMember::where('client_id', $selectedClient->id)
                ->whereHas('group', fn($q) => $q->where('club_id', $club->id))
                ->with('group')
                ->get();

            // Пробные занятия клиента: как гость (client_id) или как член группы.
            $clientTrials = \App\Models\ClubGroupAttendance::where('is_trial', true)
                ->where(function ($q) use ($selectedClient) {
                    $q->where('client_id', $selectedClient->id)
                      ->orWhereHas('member', fn($m) => $m->where('client_id', $selectedClient->id));
                })
                ->whereHas('session', fn($q) => $q->whereHas('group', fn($g) => $g->where('club_id', $club->id)))
                ->with(['session.group'])
                ->get()
                ->sortByDesc(fn($a) => optional($a->session)->date)
                ->values();

            $clientCards = \App\Models\ClubCard::where('club_client_id', $selectedClient->id)
                ->where('club_id', $club->id)
                ->with('type')
                ->orderByDesc('created_at')
                ->get();

            // Активные типы карт клуба — для модалки выпуска.
            $cardTypes = \App\Models\ClubCardType::where('club_id', $club->id)
                ->active()
                ->orderBy('name')
                ->get();
        }

        // У кого есть аккаунт в приложении (зарегистрирован на этом номере).
        $clientDigits = $clients->pluck('phone')->filter()
            ->map(fn($p) => preg_replace('/\D/', '', (string) $p))
            ->filter()->values()->all();
        $appPhones = [];
        if (!empty($clientDigits)) {
            $appPhones = array_flip(
                \App\Models\User::whereIn('phone', $clientDigits)->pluck('phone')->all()
            );
        }
        foreach ($clients as $c) {
            $digits = preg_replace('/\D/', '', (string) $c->phone);
            $c->has_app = (bool) ($c->user_id || isset($appPhones[$digits]));
        }

        return view('club.clients.index', compact('clients', 'totalCount', 'selectedClient', 'clientGroups', 'clientTrials', 'clientCards', 'cardTypes'));
    }

    /**
     * Обзор клубных карт клиентов с фильтром «заканчиваются / закончились».
     * Заканчивается = срок ≤ 7 дней ИЛИ ≤ 4 визитов; закончилась = 0 визитов или просрочена.
     */
    public function cardsOverview(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $f = $request->get('f', 'all'); // all | ending | ended
        $today = Carbon::today();
        $soonEdge = $today->copy()->addDays(7);

        $cards = \App\Models\ClubCard::where('club_id', $club->id)
            ->with(['type', 'client'])
            ->get();

        $rows = $cards->map(function ($card) use ($today, $soonEdge) {
            $counter = $card->type && $card->type->isCounter();
            $bal = (int) $card->balance;
            $exp = $card->expires_at ? $card->expires_at->copy()->startOfDay() : null;
            $archived = $card->status === 'archived';
            $expired = !$archived && ($card->status !== 'active' || ($exp && $exp->lt($today)));
            $used = $counter && $bal <= 0;
            $soon = !$archived && !$expired && $exp && $exp->gte($today) && $exp->lte($soonEdge);
            $low = $counter && !$archived && !$expired && !$used && $bal > 0 && $bal <= 4;

            if ($archived) {
                $bucket = 'archived';
            } elseif ($used || $expired) {
                $bucket = 'ended';
            } elseif ($soon || $low) {
                $bucket = 'ending';
            } else {
                $bucket = 'active';
            }

            return [
                'card_id' => $card->id,
                'client_id' => optional($card->client)->id,
                'client_name' => optional($card->client)->name ?? '—',
                'type_name' => optional($card->type)->name ?? '—',
                'counter' => $counter,
                'balance' => $bal,
                'initial' => (int) $card->initial_balance,
                'expires' => optional($card->expires_at)->format('d.m.Y'),
                'expires_ts' => optional($card->expires_at)->timestamp ?? PHP_INT_MAX,
                'bucket' => $bucket,
                'archived' => $archived,
                'used' => $used,
                'expired' => $expired,
                'soon' => $soon,
                'low' => $low,
            ];
        });

        $counts = [
            'all' => $rows->where('bucket', '!=', 'archived')->count(),
            'ending' => $rows->where('bucket', 'ending')->count(),
            'ended' => $rows->where('bucket', 'ended')->count(),
            'archive' => $rows->where('bucket', 'archived')->count(),
        ];

        if (in_array($f, ['ending', 'ended', 'archive'], true)) {
            $target = $f === 'archive' ? 'archived' : $f;
            $rows = $rows->where('bucket', $target);
        } else {
            // «Все» — без архива
            $rows = $rows->where('bucket', '!=', 'archived');
        }

        $rows = $rows->values()->all();
        usort($rows, function ($a, $b) {
            if ($a['expires_ts'] !== $b['expires_ts']) return $a['expires_ts'] <=> $b['expires_ts'];
            return $a['balance'] <=> $b['balance'];
        });

        return view('club.clients.cards', compact('rows', 'counts', 'f'));
    }

    /**
     * Обзор групповых занятий клиентов: сколько осталось и в какой группе.
     * Заканчивается = осталось ≤ 2; закончились = 0.
     */
    public function groupsOverview(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $f = $request->get('f', 'all');

        $members = \App\Models\ClubGroupMember::whereHas('group', fn($q) => $q->where('club_id', $club->id))
            ->where('status', 'active')
            ->with(['group', 'client'])
            ->withSum('enrollments as bought', 'sessions')
            ->withCount(['attendance as used' => fn($q) => $q->where('charged', true)])
            ->get();

        $rows = $members->map(function ($m) {
            $remaining = (int) ($m->bought ?? 0) - (int) ($m->used ?? 0);
            $bucket = $remaining <= 0 ? 'ended' : ($remaining <= 2 ? 'ending' : 'active');
            return [
                'client_id' => $m->client_id,
                'client_name' => optional($m->client)->name ?? '—',
                'group_name' => optional($m->group)->name ?? '—',
                'remaining' => $remaining,
                'bucket' => $bucket,
            ];
        });

        $counts = [
            'all' => $rows->count(),
            'ending' => $rows->where('bucket', 'ending')->count(),
            'ended' => $rows->where('bucket', 'ended')->count(),
        ];

        if (in_array($f, ['ending', 'ended'], true)) {
            $rows = $rows->where('bucket', $f);
        }

        $rows = $rows->values()->all();
        usort($rows, fn($a, $b) => $a['remaining'] <=> $b['remaining']);

        return view('club.clients.groups', compact('rows', 'counts', 'f'));
    }

    /** Переключить архив карты (использованную/просроченную убрать из списков; либо вернуть). */
    public function archiveCard(Request $request, \App\Models\ClubCard $card)
    {
        $club = $this->getClub();
        if (!$club || $card->club_id !== $club->id) abort(403);

        $card->update(['status' => $card->status === 'archived' ? 'active' : 'archived']);

        return redirect()
            ->route('club.clients.cards', array_filter(['f' => $request->get('f')]))
            ->with('success', $card->status === 'archived' ? 'Карта перемещена в архив' : 'Карта возвращена из архива');
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
            // bare=1 → «голый» layout без админ-меню (для выезжающей панели/iframe)
            '__layout' => $request->boolean('bare') ? 'layouts.bare' : 'layouts.app',
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

        $clients = $query->orderBy('name')->limit(10)->get(['id', 'name', 'phone', 'note']);

        // Маскируем телефон в выдаче, если у клуба включено скрытие.
        // Поиск по телефону (WHERE выше) при этом продолжает работать.
        $clients->transform(function ($client) {
            $client->phone = PhoneVisibility::forExport($client->phone);
            return $client;
        });

        return response()->json($clients);
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

            // Проверка дубликата: клиент с таким нормализованным телефоном уже существует
            $duplicate = ClubClient::where('club_id', $club->id)
                ->where('phone', $validated['phone'])
                ->first();
            if ($duplicate) {
                return redirect()->route('club.clients.index', ['selected' => $duplicate->id])
                    ->withInput()
                    ->with('error', "Клиент с таким телефоном уже есть: «{$duplicate->name}». Найдите его в списке и отредактируйте, если нужно.");
            }
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

            // Проверка: нельзя поставить телефон, который уже занят другим клиентом
            $duplicate = ClubClient::where('club_id', $club->id)
                ->where('phone', $validated['phone'])
                ->where('id', '!=', $client->id)
                ->first();
            if ($duplicate) {
                return redirect()->route('club.clients.index', ['selected' => $duplicate->id])
                    ->withInput()
                    ->with('error', "Этот телефон уже принадлежит клиенту «{$duplicate->name}». Один номер не может быть у двух разных клиентов.");
            }
        }

        // Запоминаем старый телефон ДО апдейта — по нему найдём связанные брони.
        $oldPhoneVariants = $this->phoneVariants($client->phone);

        $oldValues = $client->only(array_keys($validated));
        $client->update($validated);
        $changes = $client->getChanges();
        unset($changes['updated_at']);

        // Привязка пользователя приложения (когда телефон в базе клуба не
        // совпадает с номером в приложении). Отвязка — отдельной кнопкой.
        if (trim((string) $request->input('app_link_phone')) !== '') {
            $linkUser = $this->findAppUserByPhone($request->input('app_link_phone'));
            if ($linkUser) {
                $client->update(['user_id' => $linkUser->id]);
            } else {
                $request->session()->flash('link_warning',
                    'Пользователь приложения с таким номером не найден — привязка не выполнена.');
            }
        }

        // Синхронизация имени/телефона в court_bookings.
        // Брони хранят client_name/client_phone текстом — при правке карточки
        // имя/телефон в существующих бронях не подтягиваются автоматически.
        // Находим брони клуба по старым вариантам телефона и подтягиваем актуальное.
        $bookingsUpdated = 0;
        if ($oldPhoneVariants && (array_key_exists('name', $changes) || array_key_exists('phone', $changes))) {
            $courtIds = $club->courts()->pluck('id');
            $bookingsUpdated = CourtBooking::whereIn('court_id', $courtIds)
                ->whereIn('client_phone', $oldPhoneVariants)
                ->update([
                    'client_name' => $client->name,
                    'client_phone' => $client->phone,
                ]);
        }

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
            $logMessage = "Редактирование клиента {$client->name}: " . implode(', ', $changedFields);
            if ($bookingsUpdated > 0) {
                $logMessage .= " (синхр. в {$bookingsUpdated} бронях)";
            }
            ActivityLog::log('updated', 'ClubClient', $client->id, $logMessage, ['old' => $oldValues, 'new' => $changes]);
        }

        return redirect()->route('club.clients.index', ['selected' => $client->id])
            ->with('success', 'Клиент обновлён');
    }

    /**
     * Найти пользователя приложения по номеру (терпимо к формату: +7 / 8 / пробелы).
     * Сверка по последним 10 цифрам.
     */
    private function findAppUserByPhone($raw): ?\App\Models\User
    {
        $last10 = substr(preg_replace('/\D/', '', (string) $raw), -10);
        if (strlen($last10) !== 10) {
            return null;
        }
        $tail = substr($last10, -8);
        return \App\Models\User::whereNotNull('phone')
            ->where('phone', 'like', '%' . $tail . '%')
            ->get(['id', 'phone'])
            ->first(fn($u) =>
                substr(preg_replace('/\D/', '', (string) $u->phone), -10) === $last10);
    }

    /** Отвязать пользователя приложения от клиента (отдельная кнопка «Отозвать»). */
    public function unlinkAppUser(Request $request, ClubClient $client)
    {
        $club = $this->getClub();
        if (!$club || $client->club_id !== $club->id) {
            abort(403);
        }
        $client->update(['user_id' => null]);

        return redirect()->route('club.clients.index', ['selected' => $client->id])
            ->with('success', 'Пользователь отвязан от клиента');
    }

    /**
     * Страница «Дубликаты»: показывает группы клиентов клуба с одинаковым
     * нормализованным номером телефона.
     * GET /club/clients/duplicates
     */
    public function duplicates()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $clients = ClubClient::where('club_id', $club->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('created_at')
            ->get();

        $groups = $clients->groupBy(function ($c) {
            $digits = preg_replace('/\D/', '', $c->phone);
            // Сводим к 10-значной форме без ведущей 7 — чтобы 77001234567 и 7001234567 совпали.
            if (strlen($digits) === 11 && $digits[0] === '7') {
                $digits = substr($digits, 1);
            }
            return $digits;
        })->filter(fn($g) => $g->count() > 1)
          ->map(fn($g) => $g->sortBy('created_at')->values())
          ->values();

        return view('club.clients.duplicates', [
            'club' => $club,
            'groups' => $groups,
            'totalGroups' => $groups->count(),
            'totalDuplicates' => $groups->sum(fn($g) => $g->count() - 1),
        ]);
    }

    /**
     * Слияние дубликатов в одного клиента.
     * POST /club/clients/duplicates/merge
     *
     * keep_id    — кого оставляем
     * merge_ids  — кого удаляем (их брони перепривязываются на keep_id)
     */
    public function mergeDuplicates(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $validated = $request->validate([
            'keep_id' => 'required|exists:club_clients,id',
            'merge_ids' => 'required|array|min:1',
            'merge_ids.*' => 'exists:club_clients,id',
        ]);

        $keep = ClubClient::where('club_id', $club->id)->findOrFail($validated['keep_id']);
        $merges = ClubClient::where('club_id', $club->id)
            ->whereIn('id', $validated['merge_ids'])
            ->where('id', '!=', $keep->id)
            ->get();

        if ($merges->isEmpty()) {
            return back()->with('error', 'Не выбраны дубликаты для слияния');
        }

        // Заполняем пустые поля главного из дубликатов
        foreach (['gender', 'birth_date', 'note'] as $field) {
            if (empty($keep->$field)) {
                foreach ($merges as $m) {
                    if (!empty($m->$field)) {
                        $keep->$field = $m->$field;
                        break;
                    }
                }
            }
        }
        // Заметки склеиваем, если у главного и у дублей разные
        $extraNotes = $merges->pluck('note')->filter()->unique()->filter(
            fn($n) => $n !== $keep->note
        )->values();
        if ($extraNotes->isNotEmpty()) {
            $keep->note = trim(($keep->note ? $keep->note . "\n" : '') . $extraNotes->implode("\n"));
        }
        $keep->save();

        // Перепривязываем брони с телефонов дубликатов на телефон главного
        $courtIds = $club->courts()->pluck('id');
        $bookingsRebound = 0;
        foreach ($merges as $m) {
            $oldVariants = $this->phoneVariants($m->phone);
            if (!$oldVariants) continue;
            $bookingsRebound += CourtBooking::whereIn('court_id', $courtIds)
                ->whereIn('client_phone', $oldVariants)
                ->update([
                    'client_name' => $keep->name,
                    'client_phone' => $keep->phone,
                ]);
        }

        // Удаляем дубликаты
        $mergedSnapshot = $merges->map(fn($m) => $m->only(['id', 'name', 'phone']))->toArray();
        $mergedCount = $merges->count();
        foreach ($merges as $m) {
            $m->delete();
        }

        ActivityLog::log(
            'updated',
            'ClubClient',
            $keep->id,
            "Слияние дубликатов в «{$keep->name}»: удалено {$mergedCount}, перепривязано {$bookingsRebound} броней",
            ['kept_id' => $keep->id, 'merged' => $mergedSnapshot, 'bookings_rebound' => $bookingsRebound]
        );

        return redirect()->route('club.clients.duplicates')
            ->with('success', "Объединено: удалено {$mergedCount} дубликатов, перепривязано {$bookingsRebound} броней");
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
                $phoneCell = PhoneVisibility::forExport($c->phone);
                fputcsv($out, [
                    $c->name,
                    ($phoneCell !== '' && $phoneCell !== 'скрыт') ? '+' . $phoneCell : $phoneCell,
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
