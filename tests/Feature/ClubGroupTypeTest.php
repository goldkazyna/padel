<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Вид группы: абонементная или пробная.
 * Механика проведения пока одна и та же — поле нужно, чтобы отличать их
 * в списке и дальше строить разную логику оплаты.
 */
class ClubGroupTypeTest extends TestCase
{
    use RefreshDatabase;

    private function setupClub(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        return [$club, $admin];
    }

    public function test_group_is_subscription_by_default(): void
    {
        [$club] = $this->setupClub();

        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'Без выбора']);

        // Всё, что было создано до появления поля, должно остаться абонементным.
        $this->assertSame(ClubGroup::TYPE_SUBSCRIPTION, $group->fresh()->type);
        $this->assertFalse($group->fresh()->isTrial());
        $this->assertSame('Абонемент', $group->fresh()->type_name);
    }

    public function test_can_create_trial_group(): void
    {
        [, $admin] = $this->setupClub();

        $this->actingAs($admin)->post(route('club.groups.store'), [
            'name' => 'Пробная суббота',
            'type' => 'trial',
            'price_per_session' => 4500,
        ])->assertRedirect();

        $group = ClubGroup::firstOrFail();
        $this->assertSame(ClubGroup::TYPE_TRIAL, $group->type);
        $this->assertTrue($group->isTrial());
        $this->assertSame('Пробная', $group->type_name);
    }

    public function test_create_without_type_falls_back_to_subscription(): void
    {
        [, $admin] = $this->setupClub();

        $this->actingAs($admin)->post(route('club.groups.store'), [
            'name' => 'Обычная',
        ])->assertRedirect();

        $this->assertSame(ClubGroup::TYPE_SUBSCRIPTION, ClubGroup::firstOrFail()->type);
    }

    public function test_unknown_type_is_rejected(): void
    {
        [, $admin] = $this->setupClub();

        $this->actingAs($admin)->post(route('club.groups.store'), [
            'name' => 'Странная',
            'type' => 'что-то своё',
        ])->assertSessionHasErrors('type');

        $this->assertSame(0, ClubGroup::count());
    }

    public function test_type_can_be_changed_later(): void
    {
        [$club, $admin] = $this->setupClub();
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'Была абонементной', 'price_per_session' => 3000,
        ]);

        $this->actingAs($admin)->put(route('club.groups.update', $group), [
            'name' => 'Стала пробной',
            'type' => 'trial',
            'price_per_session' => 3000,
        ])->assertRedirect();

        $this->assertSame(ClubGroup::TYPE_TRIAL, $group->fresh()->type);
    }

    public function test_trial_badge_is_shown_only_for_trial_groups(): void
    {
        [$club, $admin] = $this->setupClub();
        ClubGroup::create(['club_id' => $club->id, 'name' => 'Абонементная группа']);
        ClubGroup::create(['club_id' => $club->id, 'name' => 'Пробная группа', 'type' => 'trial']);

        $html = $this->actingAs($admin)->get(route('club.groups.index'))->assertOk()->getContent();

        // Метка одна — у пробной. Абонементные не метим, их большинство.
        $this->assertSame(1, substr_count($html, 'class="gc-type"'));
    }

    /** Групповая бронь на корте: занятие + бронь, как их создаёт раздел «Занятия». */
    private function groupBookingOn(\App\Models\Club $club, ClubGroup $group, User $admin): string
    {
        $court = \App\Models\Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        // Середина следующей недели: на последний день недели бронь не попадает
        // в недельную сетку под SQLite (дата там лежит строкой с временем).
        $date = now()->startOfWeek(\Carbon\Carbon::MONDAY)->addWeek()->addDays(2)->toDateString();

        $this->actingAs($admin)->post(route('club.groupSessions.store'), [
            'group_id' => $group->id,
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => '16:00',
            'slots' => 1,
        ])->assertRedirect();

        return $date;
    }

    public function test_schedule_marks_trial_group_booking(): void
    {
        [$club, $admin] = $this->setupClub();
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'Тестовая', 'type' => 'trial', 'price_per_session' => 2400,
        ]);
        $date = $this->groupBookingOn($club, $group, $admin);

        // В узком слоте отдельному бейджу места нет — вид пишем в самой полоске.
        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertSee('Групповая · пробная')
            ->assertDontSee('Групповая · абонемент');

        $this->actingAs($admin)
            ->get(route('club.courts.scheduleWeek', ['date' => $date]))
            ->assertOk()
            ->assertSee('Групповая · пробная');
    }

    public function test_schedule_keeps_group_label_for_subscription(): void
    {
        [$club, $admin] = $this->setupClub();
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'Обычная', 'price_per_session' => 4500,
        ]);
        $date = $this->groupBookingOn($club, $group, $admin);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertSee('Групповая · абонемент')
            ->assertDontSee('Групповая · пробная');
    }

    public function test_group_page_shows_the_type(): void
    {
        [$club, $admin] = $this->setupClub();
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'Пробная группа', 'type' => 'trial',
        ]);

        $this->actingAs($admin)
            ->get(route('club.groups.show', $group))
            ->assertOk()
            ->assertSee('gsch-type', false)
            ->assertSee('Пробная');
    }
}
