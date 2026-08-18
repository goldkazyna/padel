<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Справочник контактов клуба.
 */
class ClubContactsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function group(string $name = 'Поставщики'): ContactGroup
    {
        return ContactGroup::create(['club_id' => $this->club->id, 'name' => $name]);
    }

    public function test_group_and_contact_are_created(): void
    {
        $this->actingAs($this->admin)
            ->post(route('club.contactGroups.store'), ['name' => 'Персонал'])
            ->assertRedirect();

        $group = ContactGroup::firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('club.contacts.store'), [
                'name' => 'Ержан',
                'position' => 'Электрик',
                'phone' => '+7 707 111 22 33',
                'note' => 'Приезжает в течение часа',
                'contact_group_id' => $group->id,
            ])
            ->assertRedirect();

        $contact = Contact::firstOrFail();
        $this->assertSame('Ержан', $contact->name);
        $this->assertSame($group->id, $contact->contact_group_id);
        $this->assertSame('Приезжает в течение часа', $contact->note);
    }

    /** Удаление группы не должно уносить с собой телефоны. */
    public function test_deleting_a_group_keeps_contacts(): void
    {
        $group = $this->group();
        Contact::create([
            'club_id' => $this->club->id,
            'contact_group_id' => $group->id,
            'name' => 'Мячи КЗ',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('club.contactGroups.destroy', $group))
            ->assertRedirect();

        $contact = Contact::firstOrFail();
        $this->assertNull($contact->contact_group_id, 'контакт остаётся без группы');
    }

    public function test_search_finds_by_note_and_phone(): void
    {
        Contact::create([
            'club_id' => $this->club->id,
            'name' => 'Ержан',
            'phone' => '+7 (707) 111-22-33',
            'note' => 'Чинит освещение',
        ]);
        Contact::create(['club_id' => $this->club->id, 'name' => 'Другой человек']);

        $byNote = $this->actingAs($this->admin)
            ->get(route('club.contacts.index', ['q' => 'освещение']))->assertOk();
        $byNote->assertSee('Ержан')->assertDontSee('Другой человек');

        // Телефон записан со скобками, ищем цифрами.
        $byPhone = $this->actingAs($this->admin)
            ->get(route('club.contacts.index', ['q' => '7071112233']))->assertOk();
        $byPhone->assertSee('Ержан')->assertDontSee('Другой человек');
    }

    public function test_filter_by_group(): void
    {
        $group = $this->group();
        Contact::create(['club_id' => $this->club->id, 'contact_group_id' => $group->id, 'name' => 'Мячи КЗ']);
        Contact::create(['club_id' => $this->club->id, 'name' => 'Одиночка Петров']);

        $this->actingAs($this->admin)
            ->get(route('club.contacts.index', ['group' => $group->id]))
            ->assertOk()
            ->assertSee('Мячи КЗ')
            ->assertDontSee('Одиночка Петров');

        $this->actingAs($this->admin)
            ->get(route('club.contacts.index', ['group' => 'none']))
            ->assertOk()
            ->assertSee('Одиночка Петров')
            ->assertDontSee('Мячи КЗ');
    }

    /** Чужая группа не должна утаскивать контакт в другой клуб. */
    public function test_foreign_group_is_ignored(): void
    {
        $other = Club::create(['name' => 'Чужой', 'address' => 'Б']);
        $foreignGroup = ContactGroup::create(['club_id' => $other->id, 'name' => 'Чужая']);

        $this->actingAs($this->admin)
            ->post(route('club.contacts.store'), [
                'name' => 'Кто-то',
                'contact_group_id' => $foreignGroup->id,
            ])
            ->assertRedirect();

        $this->assertNull(Contact::firstOrFail()->contact_group_id);
    }

    public function test_foreign_club_cannot_touch_contacts(): void
    {
        $contact = Contact::create(['club_id' => $this->club->id, 'name' => 'Наш']);

        $other = Club::create(['name' => 'Чужой', 'address' => 'Б']);
        $stranger = User::factory()->create(['role' => 'club_admin']);
        $stranger->adminClubs()->attach($other->id);

        $this->actingAs($stranger)
            ->delete(route('club.contacts.destroy', $contact))
            ->assertForbidden();

        $this->assertSame(1, Contact::count());
    }

    public function test_menu_has_the_section(): void
    {
        $this->actingAs($this->admin)
            ->get(route('club.contacts.index'))
            ->assertOk()
            ->assertSee('Контакты')
            ->assertSee(route('club.contacts.index'), false);
    }
}
