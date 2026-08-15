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

    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        // Вкладка active|archived (по умолчанию active).
        $tab = $request->get('tab') === 'archived' ? 'archived' : 'active';

        $base = ClubGroup::where('club_id', $club->id);

        $activeCount = (clone $base)->where('status', 'active')->count();
        $archivedCount = (clone $base)->where('status', 'archived')->count();

        $groups = $base
            ->where('status', $tab)
            ->with([
                'coach:id,name,first_name,last_name',
                // Активные участники с пакетами — для точек-остатка на карточке.
                'members' => fn($q) => $q->where('status', 'active')
                    ->with(['enrollments:id,group_member_id,sessions', 'attendance:id,group_member_id,charged']),
            ])
            ->withCount(['members as active_members_count' => fn($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();

        $coaches = $club->clubCoaches()->with('user')->get();

        // Мета аватарок тренеров: фото / инициалы / цвет-заглушка
        $palette = ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#22c55e', '#06b6d4', '#ef4444', '#14b8a6'];
        $coachMeta = [];
        foreach ($coaches as $cc) {
            if (!$cc->user) continue;
            $coachMeta[$cc->user_id] = $this->coachMeta($cc->user, $cc->photo, $palette);
        }
        foreach ($groups as $g) {
            if ($g->coach_id && !isset($coachMeta[$g->coach_id]) && $g->coach) {
                $coachMeta[$g->coach_id] = $this->coachMeta($g->coach, null, $palette);
            }
        }

        // Счётчик для кнопки «Остатки» (0 и 1–2 занятия по активным группам).
        $rem = $this->remainsData($club);
        $remCount = count($rem['ended']) + count($rem['ending']);

        return view('club.groups.index', compact('groups', 'club', 'coaches', 'coachMeta', 'tab', 'activeCount', 'archivedCount', 'remCount'));
    }

    /**
     * Отдельная страница «Остатки»: участники всех активных групп,
     * сгруппированные по «Закончились» (0) и «Заканчиваются» (1–2).
     */
    public function remains()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $rem = $this->remainsData($club);

        return view('club.groups.remains', [
            'club'      => $club,
            'remEnded'  => $rem['ended'],
            'remEnding' => $rem['ending'],
        ]);
    }

    /**
     * Считает остатки занятий по активным участникам всех активных групп.
     * Возвращает ['ended' => [...], 'ending' => [...]] отсортированные по остатку.
     */
    private function remainsData($club): array
    {
        $groups = ClubGroup::where('club_id', $club->id)
            ->where('status', 'active')
            ->with([
                'coach:id,name,first_name,last_name',
                'members' => fn($q) => $q->where('status', 'active')
                    ->with(['client:id,name', 'enrollments:id,group_member_id,sessions', 'attendance:id,group_member_id,charged']),
            ])
            ->orderBy('name')
            ->get();

        $ended = [];   // 0 занятий и меньше
        $ending = [];  // 1–2 занятия
        foreach ($groups as $g) {
            foreach ($g->members as $m) {
                $r = (int) $m->enrollments->sum('sessions') - $m->attendance->where('charged', true)->count();
                if ($r > 2) continue;
                $row = [
                    'name'      => optional($m->client)->name ?? '—',
                    'client_id' => optional($m->client)->id,
                    'group'     => $g->name,
                    'group_id'  => $g->id,
                    'coach'     => optional($g->coach)->full_name,
                    'rem'       => $r,
                ];
                if ($r <= 0) $ended[] = $row; else $ending[] = $row;
            }
        }
        usort($ended, fn($a, $b) => $a['rem'] <=> $b['rem']);
        usort($ending, fn($a, $b) => $a['rem'] <=> $b['rem']);

        return ['ended' => $ended, 'ending' => $ending];
    }

    /**
     * Журнал групп — все события по группам клуба (создание/отмена занятий,
     * участники, заморозки и т.д.) с фильтром по конкретной группе, типу и дате.
     */
    public function journal(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $query = \App\Models\ActivityLog::where('club_id', $club->id)
            ->whereNotNull('group_id')
            ->with(['user', 'group:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('group')) {
            $query->where('group_id', (int) $request->get('group'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->get('action'));
        }
        if ($request->filled('search')) {
            $query->where('description', 'like', '%' . $request->get('search') . '%');
        }
        $date = $request->get('date');
        if ($date) {
            $start = \Carbon\Carbon::parse($date, 'Asia/Almaty')->startOfDay()->utc();
            $end = \Carbon\Carbon::parse($date, 'Asia/Almaty')->endOfDay()->utc();
            $query->whereBetween('created_at', [$start, $end]);
        }

        $logs = $query->paginate(30)->withQueryString();
        $groupedLogs = $logs->getCollection()
            ->groupBy(fn($log) => $log->created_at->timezone('Asia/Almaty')->format('Y-m-d'));

        // Список групп для фильтра (активные + архивные, по имени).
        $groups = ClubGroup::where('club_id', $club->id)->orderBy('name')->get(['id', 'name', 'status']);

        // Статистика по журналу групп.
        $base = \App\Models\ActivityLog::where('club_id', $club->id)->whereNotNull('group_id');
        $stats = [
            'total'     => (clone $base)->count(),
            'sessions'  => (clone $base)->where('subject_type', 'ClubGroupSession')->count(),
            'cancelled' => (clone $base)->where('action', 'cancelled')->count(),
            'conducted' => (clone $base)->where('action', 'conducted')->count(),
        ];

        $selectedGroup = $request->filled('group')
            ? $groups->firstWhere('id', (int) $request->get('group'))
            : null;

        return view('club.groups.journal', compact(
            'club', 'logs', 'groupedLogs', 'groups', 'stats', 'date', 'selectedGroup'
        ));
    }

    /** Метаданные тренера для аватарки: фото / инициалы / цвет-заглушка. */
    private function coachMeta($user, ?string $photo, array $palette): array
    {
        $fn = $user->first_name ?: $user->name;
        $ln = $user->last_name;
        $initials = mb_strtoupper(mb_substr((string) $fn, 0, 1) . ($ln ? mb_substr($ln, 0, 1) : ''));
        return [
            'photo'    => $photo,
            'initials' => $initials ?: '?',
            'color'    => $palette[$user->id % count($palette)],
            'name'     => $user->full_name,
        ];
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
        \App\Models\ActivityLog::logGroup($group->id, 'created', 'ClubGroup', $group->id,
            "Группа создана: {$group->name}", clubId: $club->id);

        return redirect()->route('club.groups.show', $group)->with('success', 'Группа создана');
    }

    public function show(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $group->load(['coach', 'members.client', 'members.enrollments', 'members.attendance', 'members.freezes']);
        $sessions = $group->sessions()
            ->with(['court', 'coach', 'attendance.client'])
            ->orderByDesc('date')->orderByDesc('start_time')->get();
        $coaches = $club->clubCoaches()->with('user')->get();
        $clients = ClubClient::where('club_id', $club->id)->orderBy('name')->get();

        return view('club.groups.show', compact('group', 'club', 'sessions', 'coaches', 'clients'));
    }

    /** Полное расписание группы: все занятия (прошедшие + будущие) с посещаемостью. */
    public function schedule(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $group->load([
            'coach',
            'members' => fn($q) => $q->where('status', 'active')->orderBy('id'),
            'members.client',
            'members.enrollments',
            'members.attendance',
            'members.freezes',
        ]);

        $sessions = $group->sessions()
            ->with(['court', 'coach', 'attendance.client'])
            ->orderByDesc('date')->orderByDesc('start_time')
            ->get();

        return view('club.groups.schedule', compact('club', 'group', 'sessions'));
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

        // Фиксируем «было → стало» по значимым полям для журнала.
        $labels = [
            'name' => 'Название', 'coach_id' => 'Тренер', 'price_per_session' => 'Цена занятия',
            'capacity' => 'Вместимость', 'note' => 'Заметка', 'status' => 'Статус',
        ];
        $changes = [];
        foreach ($labels as $field => $label) {
            if (!array_key_exists($field, $validated)) continue;
            $old = $group->getOriginal($field);
            $new = $validated[$field];
            if ((string) $old === (string) $new) continue;
            $fmt = fn($v) => $field === 'coach_id' ? (optional(\App\Models\User::find($v))->full_name ?? '—') : ($v === null || $v === '' ? '—' : $v);
            $changes[$label] = ['old' => $fmt($old), 'new' => $fmt($new)];
        }

        $group->update($validated);

        \App\Models\ActivityLog::logGroup($group->id, 'updated', 'ClubGroup', $group->id,
            "Группа изменена: {$group->name}", $changes ?: null, clubId: $club->id);

        return back()->with('success', 'Группа обновлена');
    }

    public function archive(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $cancelledCount = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($group, &$cancelledCount) {
            // Отменяем будущие/не отменённые брони — корты освободятся.
            // CourtBooking::booted-хук автоматически переведёт сессии в cancelled.
            $group->sessions()
                ->where('status', '!=', 'cancelled')
                ->with('courtBooking')
                ->get()
                ->each(function ($session) use (&$cancelledCount) {
                    if ($session->courtBooking && $session->courtBooking->status !== 'cancelled') {
                        // Помечаем сессию до брони — чтобы booted-хук брони не
                        // залогировал её отдельно (причину даём на уровне группы).
                        $session->update(['status' => 'cancelled']);
                        $session->courtBooking->update([
                            'status' => 'cancelled',
                            'cancelled_at' => now(),
                        ]);
                        $cancelledCount++;
                    }
                });

            $group->update(['status' => 'archived']);
        });

        $suffix = $cancelledCount > 0 ? " — отменено {$cancelledCount} будущих занятий" : '';
        \App\Models\ActivityLog::logGroup($group->id, 'updated', 'ClubGroup', $group->id,
            "Группа в архиве: {$group->name}{$suffix}", clubId: $club->id);

        return redirect()->route('club.groups.index', ['tab' => 'archived'])
            ->with('success', 'Группа перенесена в архив');
    }

    public function unarchive(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $group->update(['status' => 'active']);

        \App\Models\ActivityLog::logGroup($group->id, 'updated', 'ClubGroup', $group->id,
            "Группа возвращена из архива: {$group->name}", clubId: $club->id);

        return redirect()->route('club.groups.show', $group)
            ->with('success', 'Группа возвращена из архива');
    }

    public function destroy(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $groupId = $group->id;
        $groupName = $group->name;
        $cancelledCount = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($group, &$cancelledCount) {
            // У всех НЕ отменённых занятий — отменяем связанную бронь,
            // чтобы корты освободились в общем расписании.
            // (Затем cascadeOnDelete снесёт сами сессии вместе с группой.)
            $group->sessions()
                ->where('status', '!=', 'cancelled')
                ->with('courtBooking')
                ->get()
                ->each(function ($session) use (&$cancelledCount) {
                    if ($session->courtBooking && $session->courtBooking->status !== 'cancelled') {
                        // Помечаем сессию до брони — чтобы booted-хук брони не
                        // залогировал её отдельно (причину даём на уровне группы).
                        $session->update(['status' => 'cancelled']);
                        $session->courtBooking->update([
                            'status' => 'cancelled',
                            'cancelled_at' => now(),
                        ]);
                        $cancelledCount++;
                    }
                });

            $group->delete();
        });

        $suffix = $cancelledCount > 0 ? " — отменено {$cancelledCount} будущих занятий" : '';
        \App\Models\ActivityLog::logGroup($groupId, 'deleted', 'ClubGroup', $groupId,
            "Группа удалена: {$groupName}{$suffix}", clubId: $club->id);

        return redirect()->route('club.groups.index')->with('success', 'Группа удалена');
    }

    public function addMember(Request $request, ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $validated = $request->validate([
            'client_id' => 'required|exists:club_clients,id',
            'sessions' => 'required|integer|min:1|max:200',
            'amount' => 'nullable|numeric|min:0',
            'is_paid' => 'nullable|boolean',
            'subscription_ends_at' => 'nullable|date',
            'starts_at' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
            'payment_method' => 'nullable|in:cash,card,kaspi,certificate,club_card,deposit,cashback,cashless,free',
        ]);

        $client = \App\Models\ClubClient::find($validated['client_id']);
        if (!$client || $client->club_id !== $club->id) abort(403);

        // Мог остаться неактивный след от прошлого участия (мягкое удаление).
        $existing = $group->members()->where('client_id', $client->id)->first();
        if ($existing && $existing->status === 'active') {
            return back()->with('error', 'Клиент уже в этой группе');
        }

        if ($group->capacity !== null
            && $group->members()->where('status', 'active')->count() >= $group->capacity) {
            return back()->with('error', 'Группа заполнена (достигнута вместимость)');
        }

        $isRestore = (bool) $existing;
        if ($existing) {
            // Возвращаем «бывшего» участника — сохраняем его историю (посещаемость/пакеты).
            $existing->update([
                'status' => 'active',
                'left_at' => null,
                'subscription_ends_at' => $validated['subscription_ends_at'] ?? null,
                'starts_at' => $validated['starts_at'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);
            $member = $existing;
        } else {
            $member = \App\Models\ClubGroupMember::create([
                'group_id' => $group->id,
                'client_id' => $client->id,
                'subscription_ends_at' => $validated['subscription_ends_at'] ?? null,
                'starts_at' => $validated['starts_at'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);
        }
        $this->createEnrollment($member, $validated);

        $verb = $isRestore ? 'возвращён в группу' : 'добавлен в группу';
        \App\Models\ActivityLog::logGroup($group->id, $isRestore ? 'restored' : 'created', 'ClubGroupMember', $member->id,
            "{$client->name} {$verb} «{$group->name}» ({$validated['sessions']} занятий)", clubId: $club->id);

        // Состав изменился — пересчитываем цену будущих занятий группы.
        // Прошедшие не трогаем: их деньги уже в отчётах.
        app(\App\Services\GroupSessionService::class)->syncPlannedSessionsOfGroup($group->id);

        return back()->with('success', 'Участник добавлен');
    }

    /** Обновить абонемент участника (пока только опциональная дата окончания). */
    public function updateMember(Request $request, ClubGroup $group, \App\Models\ClubGroupMember $member)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id || $member->group_id !== $group->id) abort(403);

        $validated = $request->validate([
            'subscription_ends_at' => 'nullable|date',
            'starts_at' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
            'payment_method' => 'nullable|in:cash,card,kaspi,certificate,club_card,deposit,cashback,cashless,free',
        ]);
        // Фиксируем изменения абонемента для журнала.
        $fmtDate = fn($v) => $v ? \Carbon\Carbon::parse($v)->format('d.m.Y') : '—';
        $changes = [];
        $subOld = optional($member->subscription_ends_at)->format('Y-m-d');
        $subNew = $validated['subscription_ends_at'] ?? null;
        if ($subOld !== $subNew) $changes['Абонемент до'] = ['old' => $fmtDate($subOld), 'new' => $fmtDate($subNew)];
        $startOld = optional($member->starts_at)->format('Y-m-d');
        $startNew = $validated['starts_at'] ?? null;
        if ($startOld !== $startNew) $changes['Начало'] = ['old' => $fmtDate($startOld), 'new' => $fmtDate($startNew)];
        if ((string) $member->note !== (string) ($validated['note'] ?? '')) {
            $changes['Заметка'] = ['old' => $member->note ?: '—', 'new' => ($validated['note'] ?? '') ?: '—'];
        }

        $member->update([
            'subscription_ends_at' => $validated['subscription_ends_at'] ?? null,
            'starts_at' => $validated['starts_at'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);

        // Способ оплаты правим у последнего пакета участника (он привязан к пакету).
        $lastEnrollment = $member->enrollments()->latest('id')->first();
        if ($lastEnrollment) {
            $lastEnrollment->update(['payment_method' => $validated['payment_method'] ?? null]);
        }

        \App\Models\ActivityLog::logGroup($group->id, 'updated', 'ClubGroupMember', $member->id,
            "Абонемент изменён: {$member->client?->name} («{$group->name}»)", $changes ?: null, clubId: $club->id);

        return back()->with('success', 'Абонемент участника обновлён');
    }

    public function enroll(Request $request, ClubGroup $group, \App\Models\ClubGroupMember $member)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id || $member->group_id !== $group->id) abort(403);

        $validated = $request->validate([
            'sessions' => 'required|integer|min:1|max:200',
            'amount' => 'nullable|numeric|min:0',
            'is_paid' => 'nullable|boolean',
            'payment_method' => 'nullable|in:cash,card,kaspi,certificate,club_card,deposit,cashback,cashless,free',
        ]);
        $this->createEnrollment($member, $validated);

        $amount = (float) ($validated['amount'] ?? 0);
        $paid = !empty($validated['is_paid']) ? 'оплачено' : 'не оплачено';
        $sum = $amount > 0 ? ', ' . number_format($amount, 0, '', ' ') . ' ₸ (' . $paid . ')' : '';
        \App\Models\ActivityLog::logGroup($group->id, 'enrolled', 'ClubGroupMember', $member->id,
            "Продление абонемента: {$member->client?->name} +{$validated['sessions']} занятий{$sum}", clubId: $club->id);

        return back()->with('success', 'Пакет занятий добавлен');
    }

    public function removeMember(Request $request, ClubGroup $group, \App\Models\ClubGroupMember $member)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id || $member->group_id !== $group->id) abort(403);

        // Остаток пакета до удаления (куплено − проведённые).
        $remaining = $member->remaining;

        // Мягкое удаление: помечаем неактивным и фиксируем дату ухода.
        // Записи посещаемости/пакетов НЕ удаляем — они нужны для отчётов и выручки.
        $member->update(['status' => 'inactive', 'left_at' => now()]);

        \App\Models\ActivityLog::logGroup($group->id, 'deleted', 'ClubGroupMember', $member->id,
            "Участник убран из группы «{$group->name}»: {$member->client?->name}", clubId: $club->id);

        // По выбору админа — обнулить остаток занятий. Пишем компенсирующую
        // запись пакета на −остаток (с историей, без удаления данных), чтобы
        // `remaining` стал 0.
        $zeroed = false;
        if ($request->boolean('zero_balance') && $remaining > 0) {
            \App\Models\ClubGroupEnrollment::create([
                'group_member_id' => $member->id,
                'sessions' => -$remaining,
                'amount' => 0,
                'is_paid' => true,
                'payment_method' => null,
                'created_by' => auth()->id(),
            ]);
            \App\Models\ActivityLog::logGroup($group->id, 'enrolled', 'ClubGroupMember', $member->id,
                "Остаток обнулён при удалении: −{$remaining} занятий ({$member->client?->name})", clubId: $club->id);
            $zeroed = true;
        }

        // Состав изменился — пересчитываем цену будущих занятий группы.
        // Прошедшие не трогаем: их деньги уже в отчётах.
        app(\App\Services\GroupSessionService::class)->syncPlannedSessionsOfGroup($group->id);

        return back()->with('success', $zeroed
            ? "Участник убран, остаток обнулён (−{$remaining})"
            : 'Участник убран из группы');
    }

    /** Заморозить участника на период (даты включительно). */
    public function freezeMember(Request $request, ClubGroup $group, \App\Models\ClubGroupMember $member)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id || $member->group_id !== $group->id) abort(403);

        $validated = $request->validate([
            'freeze_from' => 'required|date',
            'freeze_until' => 'required|date|after_or_equal:freeze_from',
            'note' => 'nullable|string|max:255',
        ]);

        \App\Models\ClubGroupMemberFreeze::create([
            'group_member_id' => $member->id,
            'freeze_from' => $validated['freeze_from'],
            'freeze_until' => $validated['freeze_until'],
            'note' => $validated['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        // Заморозка продлевает абонемент на свою длительность (если дата окончания задана).
        $freezeDays = \Carbon\Carbon::parse($validated['freeze_from'])
            ->diffInDays(\Carbon\Carbon::parse($validated['freeze_until']));
        if ($member->subscription_ends_at && $freezeDays > 0) {
            $member->update([
                'subscription_ends_at' => $member->subscription_ends_at->copy()->addDays($freezeDays),
            ]);
        }

        $from = \Carbon\Carbon::parse($validated['freeze_from'])->format('d.m.Y');
        $until = \Carbon\Carbon::parse($validated['freeze_until'])->format('d.m.Y');
        $noteTxt = !empty($validated['note']) ? ' — ' . $validated['note'] : '';
        \App\Models\ActivityLog::logGroup($group->id, 'frozen', 'ClubGroupMember', $member->id,
            "Заморозка: {$member->client?->name} на {$freezeDays} дн. ({$from}–{$until}){$noteTxt}", clubId: $club->id);

        return back()->with('success', 'Заморозка добавлена');
    }

    /** Снять (удалить) период заморозки. */
    public function unfreezeMember(ClubGroup $group, \App\Models\ClubGroupMember $member, \App\Models\ClubGroupMemberFreeze $freeze)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id || $member->group_id !== $group->id) abort(403);
        if ($freeze->group_member_id !== $member->id) abort(403);

        // Снятие заморозки откатывает продление абонемента на её длительность.
        $freezeDays = $freeze->freeze_from->diffInDays($freeze->freeze_until);
        if ($member->subscription_ends_at && $freezeDays > 0) {
            $member->update([
                'subscription_ends_at' => $member->subscription_ends_at->copy()->subDays($freezeDays),
            ]);
        }

        $from = $freeze->freeze_from->format('d.m.Y');
        $until = $freeze->freeze_until->format('d.m.Y');
        $freeze->delete();

        \App\Models\ActivityLog::logGroup($group->id, 'unfrozen', 'ClubGroupMember', $member->id,
            "Заморозка снята: {$member->client?->name} ({$from}–{$until})", clubId: $club->id);

        return back()->with('success', 'Заморозка снята');
    }

    private function createEnrollment(\App\Models\ClubGroupMember $member, array $validated): void
    {
        \App\Models\ClubGroupEnrollment::create([
            'group_member_id' => $member->id,
            'sessions' => $validated['sessions'],
            'amount' => $validated['amount'] ?? 0,
            'is_paid' => (bool) ($validated['is_paid'] ?? false),
            'payment_method' => $validated['payment_method'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }
}
