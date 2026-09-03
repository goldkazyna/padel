<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupMembersTest extends TestCase
{
    use RefreshDatabase;

    private function setup3(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000, 'capacity' => 2]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        return [$club, $admin, $group, $client];
    }

    public function test_add_member_with_package_sets_remaining(): void
    {
        [, $admin, $group, $client] = $this->setup3();

        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id,
            'sessions' => 8,
            'amount' => 8000,
            'is_paid' => 1,
        ])->assertRedirect();

        $member = ClubGroupMember::where('group_id', $group->id)->where('client_id', $client->id)->first();
        $this->assertNotNull($member);
        $this->assertSame(8, $member->remaining);
        $this->assertSame(8000.0, (float) $member->enrollments()->sum('amount'));
    }

    public function test_правка_участника_меняет_последний_пакет(): void
    {
        [, $admin, $group, $client] = $this->setup3();

        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id,
            'sessions' => 8,
            'amount' => 8000,
            'is_paid' => 1,
        ])->assertRedirect();

        $member = ClubGroupMember::where('group_id', $group->id)->firstOrFail();

        // Ошиблись при добавлении: занятий 12, денег меньше и ещё не оплачено.
        $this->actingAs($admin)->put(route('club.groups.members.update', [$group, $member]), [
            'sessions' => 12,
            'amount' => 10000,
            'payment_method' => 'kaspi',
        ])->assertRedirect();

        $enrollment = $member->enrollments()->latest('id')->firstOrFail();
        $this->assertSame(12, $enrollment->sessions);
        $this->assertSame(10000.0, (float) $enrollment->amount);
        $this->assertFalse((bool) $enrollment->is_paid, 'галку сняли — значит не оплачено');
        $this->assertSame('kaspi', $enrollment->payment_method);

        // Остаток пересчитывается: занятий стало больше.
        $this->assertSame(12, $member->fresh()->remaining);
    }

    public function test_правка_без_полей_пакета_его_не_ломает(): void
    {
        [, $admin, $group, $client] = $this->setup3();

        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id,
            'sessions' => 8,
            'amount' => 8000,
            'is_paid' => 1,
        ])->assertRedirect();

        $member = ClubGroupMember::where('group_id', $group->id)->firstOrFail();

        // Меняем только заметку — пакет должен остаться прежним.
        $this->actingAs($admin)->put(route('club.groups.members.update', [$group, $member]), [
            'note' => 'Ходит с сестрой',
            'is_paid' => 1,
            'payment_method' => 'cash',
        ])->assertRedirect();

        $enrollment = $member->enrollments()->latest('id')->firstOrFail();
        $this->assertSame(8, $enrollment->sessions);
        $this->assertSame(8000.0, (float) $enrollment->amount);
        $this->assertSame(8, $member->fresh()->remaining);
    }

    public function test_enroll_extends_remaining(): void
    {
        [, $admin, $group, $client] = $this->setup3();
        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id, 'sessions' => 8, 'amount' => 8000, 'is_paid' => 1,
        ]);
        $member = ClubGroupMember::first();

        $this->actingAs($admin)->post(route('club.groups.members.enroll', [$group, $member]), [
            'sessions' => 4, 'amount' => 4000, 'is_paid' => 0,
        ])->assertRedirect();

        $this->assertSame(12, $member->fresh()->remaining);
    }

    public function test_capacity_blocks_third_member(): void
    {
        [$club, $admin, $group, $client] = $this->setup3();
        $c2 = ClubClient::create(['club_id' => $club->id, 'name' => 'Пётр']);
        $c3 = ClubClient::create(['club_id' => $club->id, 'name' => 'Сидор']);
        foreach ([$client, $c2] as $c) {
            $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
                'client_id' => $c->id, 'sessions' => 1, 'amount' => 1000, 'is_paid' => 1,
            ]);
        }
        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $c3->id, 'sessions' => 1, 'amount' => 1000, 'is_paid' => 1,
        ])->assertSessionHas('error');

        $this->assertSame(2, $group->members()->count());
    }
}
