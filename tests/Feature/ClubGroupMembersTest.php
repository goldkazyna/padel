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

    public function test_правка_ставит_остаток_а_не_добавляет_занятия(): void
    {
        [, $admin, $group, $client] = $this->setup3();

        // Два пакета: 8 + 4 = 12 куплено. Одно занятие уже проведено —
        // остаток 11, и именно его админ видит в списке и в форме.
        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id, 'sessions' => 8, 'amount' => 32000, 'is_paid' => 1,
        ])->assertRedirect();

        $member = ClubGroupMember::where('group_id', $group->id)->firstOrFail();
        $this->actingAs($admin)->post(route('club.groups.members.enroll', [$group, $member]), [
            'sessions' => 4, 'amount' => 16000, 'is_paid' => 1,
        ])->assertRedirect();

        $session = \App\Models\ClubGroupSession::create([
            'group_id' => $group->id, 'date' => now()->subDay()->toDateString(),
            'court_id' => \App\Models\Court::create([
                'club_id' => $group->club_id, 'name' => 'Корт 1',
                'open_time' => '08:00:00', 'close_time' => '22:00:00', 'slot_duration' => 60,
            ])->id,
            'start_time' => '10:00:00', 'end_time' => '11:00:00', 'status' => 'held',
        ]);
        \App\Models\ClubGroupAttendance::create([
            'session_id' => $session->id, 'group_member_id' => $member->id,
            'attended' => true, 'charged' => true,
        ]);

        $this->assertSame(11, $member->fresh()->remaining);

        // Ставим в форме 12 — это остаток, а не «добавить 12 занятий».
        $this->actingAs($admin)->put(route('club.groups.members.update', [$group, $member]), [
            'sessions' => 12, 'amount' => 45600, 'is_paid' => 1, 'payment_method' => 'kaspi',
        ])->assertRedirect();

        $this->assertSame(12, $member->fresh()->remaining, 'остаток стал ровно таким, как ввели');

        $enrollment = $member->enrollments()->latest('id')->firstOrFail();
        $this->assertSame(45600.0, (float) $enrollment->amount);
        $this->assertSame('kaspi', $enrollment->payment_method);
    }

    public function test_правка_только_суммы_остаток_не_трогает(): void
    {
        [, $admin, $group, $client] = $this->setup3();

        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id, 'sessions' => 8, 'amount' => 32000, 'is_paid' => 1,
        ])->assertRedirect();

        $member = ClubGroupMember::where('group_id', $group->id)->firstOrFail();

        // Пришли поправить сумму — занятия остаются как были.
        $this->actingAs($admin)->put(route('club.groups.members.update', [$group, $member]), [
            'sessions' => 8, 'amount' => 45600, 'is_paid' => 1,
        ])->assertRedirect();

        $this->assertSame(8, $member->fresh()->remaining);
        $this->assertSame(45600.0, (float) $member->enrollments()->latest('id')->first()->amount);
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

    public function test_в_списке_видно_оплату_по_последнему_пакету(): void
    {
        [, $admin, $group, $client] = $this->setup3();

        // Первый пакет оплачен, потом абонемент переоформили — в строке
        // должна стоять сумма последнего пакета, а не сумма за всё время:
        // пакеты переписывают, и история превращается в двойной счёт.
        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id, 'sessions' => 8, 'amount' => 70000, 'is_paid' => 1,
        ])->assertRedirect();

        $member = ClubGroupMember::where('group_id', $group->id)->firstOrFail();
        $this->actingAs($admin)->post(route('club.groups.members.enroll', [$group, $member]), [
            'sessions' => 8, 'amount' => 70000, 'is_paid' => 1,
        ])->assertRedirect();

        $this->actingAs($admin)->get(route('club.groups.show', $group))
            ->assertOk()
            ->assertSee('Оплачено 70 000')
            ->assertDontSee('140 000');
    }

    public function test_неоплаченный_пакет_помечен(): void
    {
        [, $admin, $group, $client] = $this->setup3();

        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id, 'sessions' => 8, 'amount' => 18000,
        ])->assertRedirect();

        $this->actingAs($admin)->get(route('club.groups.show', $group))
            ->assertOk()
            ->assertSee('Не оплачено 18 000');
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
