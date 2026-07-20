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
            ->with('coach:id,name,first_name,last_name')
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

        return view('club.groups.index', compact('groups', 'club', 'coaches', 'coachMeta', 'tab', 'activeCount', 'archivedCount'));
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
        \App\Models\ActivityLog::log('created', 'ClubGroup', $group->id, "Группа создана: {$group->name}", clubId: $club->id);

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

        $group->update($validated);

        return back()->with('success', 'Группа обновлена');
    }

    public function archive(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        \Illuminate\Support\Facades\DB::transaction(function () use ($group) {
            // Отменяем будущие/не отменённые брони — корты освободятся.
            // CourtBooking::booted-хук автоматически переведёт сессии в cancelled.
            $group->sessions()
                ->where('status', '!=', 'cancelled')
                ->with('courtBooking')
                ->get()
                ->each(function ($session) {
                    if ($session->courtBooking && $session->courtBooking->status !== 'cancelled') {
                        $session->courtBooking->update([
                            'status' => 'cancelled',
                            'cancelled_at' => now(),
                        ]);
                    }
                });

            $group->update(['status' => 'archived']);
        });

        \App\Models\ActivityLog::log('updated', 'ClubGroup', $group->id,
            "Группа в архиве: {$group->name}", clubId: $club->id);

        return redirect()->route('club.groups.index', ['tab' => 'archived'])
            ->with('success', 'Группа перенесена в архив');
    }

    public function unarchive(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        $group->update(['status' => 'active']);

        \App\Models\ActivityLog::log('updated', 'ClubGroup', $group->id,
            "Группа возвращена из архива: {$group->name}", clubId: $club->id);

        return redirect()->route('club.groups.show', $group)
            ->with('success', 'Группа возвращена из архива');
    }

    public function destroy(ClubGroup $group)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id) abort(403);

        \Illuminate\Support\Facades\DB::transaction(function () use ($group) {
            // У всех НЕ отменённых занятий — отменяем связанную бронь,
            // чтобы корты освободились в общем расписании.
            // (Затем cascadeOnDelete снесёт сами сессии вместе с группой.)
            $group->sessions()
                ->where('status', '!=', 'cancelled')
                ->with('courtBooking')
                ->get()
                ->each(function ($session) {
                    if ($session->courtBooking && $session->courtBooking->status !== 'cancelled') {
                        $session->courtBooking->update([
                            'status' => 'cancelled',
                            'cancelled_at' => now(),
                        ]);
                    }
                });

            $group->delete();
        });

        \App\Models\ActivityLog::log('deleted', 'ClubGroup', $group->id,
            "Группа удалена: {$group->name}", clubId: $club->id);

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
            'payment_method' => 'nullable|in:cash,card,kaspi,certificate,club_card,deposit,cashback,cashless,free',
        ]);

        $client = \App\Models\ClubClient::find($validated['client_id']);
        if (!$client || $client->club_id !== $club->id) abort(403);

        if ($group->members()->where('client_id', $client->id)->exists()) {
            return back()->with('error', 'Клиент уже в этой группе');
        }

        if ($group->capacity !== null
            && $group->members()->where('status', 'active')->count() >= $group->capacity) {
            return back()->with('error', 'Группа заполнена (достигнута вместимость)');
        }

        $member = \App\Models\ClubGroupMember::create([
            'group_id' => $group->id,
            'client_id' => $client->id,
            'subscription_ends_at' => $validated['subscription_ends_at'] ?? null,
        ]);
        $this->createEnrollment($member, $validated);

        \App\Models\ActivityLog::log('created', 'ClubGroupMember', $member->id,
            "В группу «{$group->name}» добавлен {$client->name} ({$validated['sessions']} занятий)", clubId: $club->id);

        return back()->with('success', 'Участник добавлен');
    }

    /** Обновить абонемент участника (пока только опциональная дата окончания). */
    public function updateMember(Request $request, ClubGroup $group, \App\Models\ClubGroupMember $member)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id || $member->group_id !== $group->id) abort(403);

        $validated = $request->validate([
            'subscription_ends_at' => 'nullable|date',
            'payment_method' => 'nullable|in:cash,card,kaspi,certificate,club_card,deposit,cashback,cashless,free',
        ]);
        $member->update(['subscription_ends_at' => $validated['subscription_ends_at'] ?? null]);

        // Способ оплаты правим у последнего пакета участника (он привязан к пакету).
        $lastEnrollment = $member->enrollments()->latest('id')->first();
        if ($lastEnrollment) {
            $lastEnrollment->update(['payment_method' => $validated['payment_method'] ?? null]);
        }

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

        return back()->with('success', 'Пакет занятий добавлен');
    }

    public function removeMember(ClubGroup $group, \App\Models\ClubGroupMember $member)
    {
        $club = $this->getClub();
        if (!$club || $group->club_id !== $club->id || $member->group_id !== $group->id) abort(403);

        $member->delete();
        return back()->with('success', 'Участник убран из группы');
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

        $freeze->delete();
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
