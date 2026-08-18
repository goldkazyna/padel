<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Shift;
use App\Models\ShiftChecklistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Блокировка входа: менеджер без открытой смены попадает только на
 * чек-лист открытия. Админов клуба и супер-админа это не касается —
 * они не работают в смену.
 */
class ShiftGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeClub(bool $withItems = true): Club
    {
        $club = Club::create([
            'name' => 'Клуб',
            'address' => 'Адрес',
            'city' => 'Алматы',
            'features' => ['shifts' => true, 'courts' => true],
        ]);

        if ($withItems) {
            ShiftChecklistItem::create([
                'club_id' => $club->id,
                'type' => 'opening',
                'title' => 'Проверить корты',
            ]);
        }

        return $club;
    }

    private function makeManager(Club $club): User
    {
        $user = User::factory()->create(['role' => 'club_moderator']);
        $user->moderatorClubs()->attach($club->id);

        return $user;
    }

    private function makeAdmin(Club $club): User
    {
        $user = User::factory()->create(['role' => 'club_admin']);
        $user->adminClubs()->attach($club->id);

        return $user;
    }

    public function test_manager_without_shift_is_sent_to_checklist(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);

        $this->actingAs($manager)
            ->get(route('club.dashboard'))
            ->assertRedirect(route('club.shift.opening'));
    }

    public function test_manager_is_sent_to_checklist_from_common_dashboard(): void
    {
        // После логина менеджер попадает на общий /dashboard, а не в раздел
        // клуба. Если не перехватить здесь, чек-лист он просто не увидит.
        $club = $this->makeClub();
        $manager = $this->makeManager($club);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertRedirect(route('club.shift.opening'));
    }

    public function test_player_is_never_blocked_on_common_pages(): void
    {
        $this->makeClub();
        $player = User::factory()->create(['role' => 'player']);

        $this->actingAs($player)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_manager_with_open_shift_works_normally(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        Shift::create([
            'club_id' => $club->id,
            'user_id' => $manager->id,
            'opened_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get(route('club.dashboard'))
            ->assertOk();
    }

    public function test_logout_sends_manager_to_closing_checklist(): void
    {
        // «Выйти» при открытой смене — это и есть конец смены: сначала
        // чек-лист закрытия, разлогинит уже он сам.
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        Shift::create([
            'club_id' => $club->id,
            'user_id' => $manager->id,
            'opened_at' => now(),
        ]);

        $this->actingAs($manager)
            ->post(route('logout'))
            ->assertRedirect(route('club.shift.closing'));

        $this->assertAuthenticatedAs($manager);
    }

    public function test_manager_without_shift_logs_out_normally(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);

        $this->actingAs($manager)->post(route('logout'))->assertRedirect('/');

        $this->assertGuest('web');
    }

    public function test_player_logs_out_normally(): void
    {
        $player = User::factory()->create(['role' => 'player']);

        $this->actingAs($player)->post(route('logout'))->assertRedirect('/');

        $this->assertGuest('web');
    }

    public function test_checklist_page_itself_is_not_blocked(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);

        $this->actingAs($manager)
            ->get(route('club.shift.opening'))
            ->assertOk()
            ->assertSee('Проверить корты');
    }

    public function test_club_admin_is_never_blocked(): void
    {
        $club = $this->makeClub();
        $admin = $this->makeAdmin($club);

        $this->actingAs($admin)
            ->get(route('club.dashboard'))
            ->assertOk();
    }

    public function test_super_admin_is_never_blocked(): void
    {
        $this->makeClub();
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin)
            ->get(route('club.dashboard'))
            ->assertOk();
    }

    public function test_club_without_items_does_not_block(): void
    {
        // Новый клуб ещё не завёл пункты — работать это мешать не должно.
        $club = $this->makeClub(withItems: false);
        $manager = $this->makeManager($club);

        $this->actingAs($manager)
            ->get(route('club.dashboard'))
            ->assertOk();
    }

    public function test_manager_opens_shift_through_form(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        $item = ShiftChecklistItem::where('club_id', $club->id)->first();

        $this->actingAs($manager)
            ->post(route('club.shift.open'), [
                'items' => [
                    $item->id => ['done' => '1', 'comment' => 'сетка порвана'],
                ],
            ])
            ->assertRedirect();

        $shift = Shift::where('user_id', $manager->id)->first();
        $this->assertNotNull($shift);
        $this->assertTrue($shift->isOpen());
        $this->assertSame('сетка порвана', $shift->results()->first()->comment);
    }

    public function test_unchecked_items_keep_manager_on_page(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        $item = ShiftChecklistItem::where('club_id', $club->id)->first();

        $this->actingAs($manager)
            ->post(route('club.shift.open'), [
                'items' => [$item->id => ['done' => '0']],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, Shift::where('user_id', $manager->id)->count());
    }

    public function test_closing_shift_logs_manager_out(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        ShiftChecklistItem::create([
            'club_id' => $club->id,
            'type' => 'closing',
            'title' => 'Выключить свет',
        ]);
        $shift = Shift::create([
            'club_id' => $club->id,
            'user_id' => $manager->id,
            'opened_at' => now()->subHours(5),
        ]);
        $closing = ShiftChecklistItem::where('type', 'closing')->first();

        $this->actingAs($manager)
            ->post(route('club.shift.close'), [
                'items' => [$closing->id => ['done' => '1']],
            ])
            ->assertRedirect();

        $this->assertNotNull($shift->fresh()->closed_at);
        $this->assertGuest('web');
    }

    /**
     * Смену закрывает менеджер кнопкой, и только он.
     *
     * Раньше в полночь система сама гнала на чек-лист закрытия: корты
     * работают за полночь, и ночная смена рвалась пополам в разгар работы.
     */
    public function test_yesterday_shift_does_not_force_closing(): void
    {
        $club = $this->makeClub();
        $manager = $this->makeManager($club);
        ShiftChecklistItem::create([
            'club_id' => $club->id,
            'type' => 'closing',
            'title' => 'Выключить свет',
        ]);
        Shift::create([
            'club_id' => $club->id,
            'user_id' => $manager->id,
            'opened_at' => now()->subDay()->setTime(9, 0),
        ]);

        $this->actingAs($manager)
            ->get(route('club.dashboard'))
            ->assertOk();
    }
}
