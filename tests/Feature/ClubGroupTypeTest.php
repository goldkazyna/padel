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
