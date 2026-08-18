<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactGroup;
use Illuminate\Http\Request;

/**
 * Справочник контактов клуба: персонал, поставщики, кто угодно.
 */
class ContactController extends Controller
{
    /** Клуб текущего пользователя — как в остальных разделах клуба. */
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();

        return $user->adminClubs()->first();
    }

    private function requireClub()
    {
        $club = $this->getClub();
        abort_if(!$club, 403, 'У вас нет клуба');

        return $club;
    }

    public function index(Request $request)
    {
        $club = $this->requireClub();
        $search = trim((string) $request->get('q'));
        $groupId = $request->get('group');

        $groups = ContactGroup::where('club_id', $club->id)
            ->withCount('contacts')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $contacts = Contact::where('club_id', $club->id)
            ->with('group:id,name')
            ->search($search)
            ->when($groupId === 'none', fn ($q) => $q->whereNull('contact_group_id'))
            ->when(is_numeric($groupId), fn ($q) => $q->where('contact_group_id', (int) $groupId))
            ->orderBy('name')
            ->get();

        $withoutGroup = Contact::where('club_id', $club->id)->whereNull('contact_group_id')->count();

        return view('club.contacts.index', compact(
            'club', 'groups', 'contacts', 'search', 'groupId', 'withoutGroup'
        ));
    }

    public function storeGroup(Request $request)
    {
        $club = $this->requireClub();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        ContactGroup::create([
            'club_id' => $club->id,
            'name' => $validated['name'],
            'sort_order' => (int) ContactGroup::where('club_id', $club->id)->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Группа добавлена');
    }

    public function updateGroup(Request $request, ContactGroup $group)
    {
        $club = $this->requireClub();
        abort_if($group->club_id !== $club->id, 403);

        $group->update($request->validate([
            'name' => 'required|string|max:255',
        ]));

        return back()->with('success', 'Группа переименована');
    }

    /**
     * Удалить группу. Контакты остаются — они станут «без группы».
     *
     * Удалять людей вместе с группой нельзя: переименовать «Поставщиков»
     * захотят многие, а потерять их телефоны — никто.
     */
    public function destroyGroup(ContactGroup $group)
    {
        $club = $this->requireClub();
        abort_if($group->club_id !== $club->id, 403);

        $left = $group->contacts()->count();
        $group->delete();

        return back()->with('success', $left > 0
            ? "Группа удалена, контакты остались без группы: {$left}"
            : 'Группа удалена');
    }

    public function store(Request $request)
    {
        $club = $this->requireClub();

        $validated = $this->validateContact($request, $club->id);
        $validated['club_id'] = $club->id;

        Contact::create($validated);

        return back()->with('success', 'Контакт добавлен');
    }

    public function update(Request $request, Contact $contact)
    {
        $club = $this->requireClub();
        abort_if($contact->club_id !== $club->id, 403);

        $contact->update($this->validateContact($request, $club->id));

        return back()->with('success', 'Контакт сохранён');
    }

    public function destroy(Contact $contact)
    {
        $club = $this->requireClub();
        abort_if($contact->club_id !== $club->id, 403);

        $contact->delete();

        return back()->with('success', 'Контакт удалён');
    }

    /** @return array<string, mixed> */
    private function validateContact(Request $request, int $clubId): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'note' => 'nullable|string|max:5000',
            'contact_group_id' => 'nullable|integer',
        ]);

        // Чужую группу подсунуть нельзя: иначе контакт уедет в другой клуб.
        $groupId = $validated['contact_group_id'] ?? null;
        $validated['contact_group_id'] = $groupId && ContactGroup::where('id', $groupId)
            ->where('club_id', $clubId)->exists()
            ? (int) $groupId
            : null;

        return $validated;
    }
}
