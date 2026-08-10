<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Shift;
use App\Models\ShiftChecklistItem;
use App\Models\ShiftChecklistResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Админская часть: управление пунктами чек-листа и журнал смен.
 */
class ShiftAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Club,1:User} */
    private function makeClubAdmin(): array
    {
        $club = Club::create([
            'name' => 'Клуб',
            'address' => 'Адрес',
            'city' => 'Алматы',
            'features' => ['shifts' => true],
        ]);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        return [$club, $admin];
    }

    public function test_super_admin_can_disable_shifts_feature(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $club = Club::create(['name' => 'C', 'address' => 'A']);

        $this->actingAs($superAdmin)->put(route('admin.clubs.update', $club), [
            'name' => 'C',
            'address' => 'A',
            'features' => ['shifts' => '0'],
        ])->assertRedirect();

        $this->assertFalse($club->fresh()->hasFeature('shifts'));
    }

    public function test_saving_club_settings_keeps_shifts_disabled(): void
    {
        // Форма шлёт весь набор модулей: выключенный флаг не должен
        // воскресать при следующем сохранении настроек клуба.
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $club = Club::create([
            'name' => 'C',
            'address' => 'A',
            'features' => ['shifts' => false],
        ]);

        $this->actingAs($superAdmin)->put(route('admin.clubs.update', $club), [
            'name' => 'C',
            'address' => 'A',
            'features' => ['shifts' => '0', 'courts' => '1'],
        ])->assertRedirect();

        $this->assertFalse($club->fresh()->hasFeature('shifts'));
    }

    public function test_admin_adds_item(): void
    {
        [$club, $admin] = $this->makeClubAdmin();

        $this->actingAs($admin)
            ->post(route('club.shiftChecklists.store'), [
                'type' => 'opening',
                'title' => 'Проверить корты',
            ])
            ->assertRedirect();

        $item = ShiftChecklistItem::where('club_id', $club->id)->first();
        $this->assertNotNull($item);
        $this->assertSame('opening', $item->type);
        $this->assertSame('Проверить корты', $item->title);
        $this->assertTrue($item->is_active);
    }

    public function test_admin_renames_and_reorders_item(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        $item = ShiftChecklistItem::create([
            'club_id' => $club->id,
            'type' => 'opening',
            'title' => 'Корты',
            'sort_order' => 0,
        ]);

        $this->actingAs($admin)
            ->put(route('club.shiftChecklists.update', $item), [
                'title' => 'Проверить корты и сетки',
                'sort_order' => 5,
            ])
            ->assertRedirect();

        $item->refresh();
        $this->assertSame('Проверить корты и сетки', $item->title);
        $this->assertSame(5, $item->sort_order);
    }

    public function test_deleting_item_only_disables_it(): void
    {
        // На пункт ссылаются прошлые смены — стирать его нельзя.
        [$club, $admin] = $this->makeClubAdmin();
        $item = ShiftChecklistItem::create([
            'club_id' => $club->id,
            'type' => 'opening',
            'title' => 'Корты',
        ]);

        $this->actingAs($admin)
            ->delete(route('club.shiftChecklists.destroy', $item))
            ->assertRedirect();

        $this->assertDatabaseHas('shift_checklist_items', [
            'id' => $item->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_cannot_touch_foreign_club_item(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        [$otherClub, $otherAdmin] = $this->makeClubAdmin();
        $foreign = ShiftChecklistItem::create([
            'club_id' => $otherClub->id,
            'type' => 'opening',
            'title' => 'Чужой пункт',
        ]);

        $this->actingAs($admin)
            ->put(route('club.shiftChecklists.update', $foreign), ['title' => 'Взлом'])
            ->assertStatus(403);

        $this->assertSame('Чужой пункт', $foreign->fresh()->title);
    }

    public function test_journal_shows_shifts_and_comments(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        $manager = User::factory()->create(['name' => 'Пётр Менеджеров', 'role' => 'club_moderator']);
        $manager->moderatorClubs()->attach($club->id);

        $shift = Shift::create([
            'club_id' => $club->id,
            'user_id' => $manager->id,
            'opened_at' => now()->subHours(8),
            'closed_at' => now(),
        ]);
        ShiftChecklistResult::create([
            'shift_id' => $shift->id,
            'type' => 'opening',
            'title_snapshot' => 'Проверить корты',
            'is_done' => true,
            'comment' => 'сетка на корте 3 порвана',
        ]);

        $this->actingAs($admin)
            ->get(route('club.shifts.index'))
            ->assertOk()
            ->assertSee('Пётр Менеджеров')
            ->assertSee('сетка на корте 3 порвана');
    }

    public function test_journal_hides_other_clubs(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        [$otherClub, $otherAdmin] = $this->makeClubAdmin();
        $stranger = User::factory()->create(['name' => 'Чужой Менеджер', 'role' => 'club_moderator']);
        $stranger->moderatorClubs()->attach($otherClub->id);

        Shift::create([
            'club_id' => $otherClub->id,
            'user_id' => $stranger->id,
            'opened_at' => now()->subHours(3),
        ]);

        $this->actingAs($admin)
            ->get(route('club.shifts.index'))
            ->assertOk()
            ->assertDontSee('Чужой Менеджер');
    }

    public function test_manager_cannot_open_admin_pages(): void
    {
        [$club, $admin] = $this->makeClubAdmin();
        $manager = User::factory()->create(['role' => 'club_moderator']);
        $manager->moderatorClubs()->attach($club->id);
        Shift::create([
            'club_id' => $club->id,
            'user_id' => $manager->id,
            'opened_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get(route('club.shiftChecklists.index'))
            ->assertStatus(403);
    }
}
