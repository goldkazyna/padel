<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Court;
use App\Models\User;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupEnrollment;
use App\Models\ClubGroupSession;
use App\Models\CourtBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubGroupAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(int $sessions = 2): array
    {
        // По умолчанию занятие ВЧЕРА 10:00–11:00 — уже закончилось, можно проводить.
        // Тесты, где надо «ещё не закончилось», переставляют дату через $session->update().
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create(['club_id' => $club->id, 'name' => 'K1', 'is_active' => true, 'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60]);
        $group = ClubGroup::create(['club_id' => $club->id, 'name' => 'G', 'price_per_session' => 1000]);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван']);
        $member = ClubGroupMember::create(['group_id' => $group->id, 'client_id' => $client->id]);
        ClubGroupEnrollment::create(['group_member_id' => $member->id, 'sessions' => $sessions, 'amount' => $sessions * 1000]);
        $date = now()->subDay()->toDateString();
        $booking = CourtBooking::create(['court_id' => $court->id, 'date' => $date, 'start_time' => '10:00', 'end_time' => '11:00', 'client_name' => 'Группа: G', 'status' => 'confirmed', 'booked_by' => $admin->id, 'price' => 0, 'booking_type' => 'group']);
        $session = ClubGroupSession::create(['group_id' => $group->id, 'court_id' => $court->id, 'court_booking_id' => $booking->id, 'date' => $date, 'start_time' => '10:00', 'end_time' => '11:00', 'status' => 'planned']);
        return [$club, $admin, $group, $member, $session, $booking];
    }

    public function test_conduct_charges_attendee(): void
    {
        [, $admin, , $member, $session] = $this->scenario(2);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['status' => 'charge']],
        ])->assertRedirect();

        $this->assertSame('held', $session->fresh()->status);
        $this->assertSame(1, $member->fresh()->remaining); // 2 - 1
    }

    public function test_conduct_blocked_when_zero_remaining(): void
    {
        [, $admin, , $member, $session] = $this->scenario(0);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['status' => 'charge']],
        ])->assertSessionHas('error');

        $this->assertSame('planned', $session->fresh()->status);
    }

    public function test_cancel_frees_court_no_charge(): void
    {
        [, $admin, , $member, $session, $booking] = $this->scenario(2);

        $this->actingAs($admin)->post(route('club.groupSessions.cancel', $session))->assertRedirect();

        $this->assertSame('cancelled', $session->fresh()->status);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(2, $member->fresh()->remaining);
    }

    public function test_reconduct_held_session_blocked(): void
    {
        [, $admin, , $member, $session] = $this->scenario(2);

        // First conduct: charge → remaining 2 -> 1
        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['status' => 'charge']],
        ])->assertRedirect();
        $this->assertSame(1, $member->fresh()->remaining);

        // Second conduct attempt on a held session → blocked, balance unchanged
        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['status' => 'charge']],
        ])->assertSessionHas('error');
        $this->assertSame(1, $member->fresh()->remaining);
    }

    public function test_cancelling_linked_booking_cancels_session(): void
    {
        [, , , , $session, $booking] = $this->scenario(2);

        // Отмена брони напрямую (как со страницы кортов)
        $booking->update(['status' => 'cancelled']);

        $this->assertSame('cancelled', $session->fresh()->status);
    }

    public function test_frozen_member_not_charged_during_freeze(): void
    {
        [, $admin, , $member, $session] = $this->scenario(2);
        // Занятие вчера; заморозка покрывает вчерашнюю дату.
        \App\Models\ClubGroupMemberFreeze::create([
            'group_member_id' => $member->id,
            'freeze_from' => now()->subDays(3)->toDateString(),
            'freeze_until' => now()->toDateString(),
        ]);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['status' => 'charge']],
        ])->assertRedirect();

        $this->assertSame('held', $session->fresh()->status);
        $this->assertSame(2, $member->fresh()->remaining, 'замороженный не списывается');
    }

    public function test_member_charged_when_session_outside_freeze(): void
    {
        [, $admin, , $member, $session] = $this->scenario(2);
        // Заморозка в будущем — вчерашнее занятие вне неё.
        \App\Models\ClubGroupMemberFreeze::create([
            'group_member_id' => $member->id,
            'freeze_from' => now()->addDays(5)->toDateString(),
            'freeze_until' => now()->addDays(10)->toDateString(),
        ]);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['status' => 'charge']],
        ])->assertRedirect();

        $this->assertSame(1, $member->fresh()->remaining, 'вне заморозки списывается');
    }

    public function test_trial_member_not_charged_and_amount_saved(): void
    {
        [, $admin, , $member, $session] = $this->scenario(2);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['status' => 'trial', 'trial_amount' => 5000]],
        ])->assertRedirect();

        $this->assertSame(2, $member->fresh()->remaining, 'пробное не тратит пакет');
        $att = \App\Models\ClubGroupAttendance::where('session_id', $session->id)
            ->where('group_member_id', $member->id)->first();
        $this->assertNotNull($att);
        $this->assertTrue((bool) $att->is_trial);
        $this->assertFalse((bool) $att->charged);
        $this->assertSame(5000, (int) $att->trial_amount);
    }

    public function test_add_trial_guest_creates_attendance_without_membership(): void
    {
        [$club, $admin, $group, $member, $session] = $this->scenario(2);
        $guest = ClubClient::create(['club_id' => $club->id, 'name' => 'Гость']);

        $this->actingAs($admin)->post(route('club.groupSessions.trialGuest', $session), [
            'client_id' => $guest->id,
            'trial_amount' => 3000,
        ])->assertRedirect();

        // Гость не стал членом группы.
        $this->assertSame(0, ClubGroupMember::where('group_id', $group->id)->where('client_id', $guest->id)->count());

        $att = \App\Models\ClubGroupAttendance::where('session_id', $session->id)
            ->where('client_id', $guest->id)->first();
        $this->assertNotNull($att);
        $this->assertTrue((bool) $att->is_trial);
        $this->assertTrue((bool) $att->attended);
        $this->assertSame(3000, (int) $att->trial_amount);

        // Остаток реального члена не задет.
        $this->assertSame(2, $member->fresh()->remaining);
    }

    public function test_remove_trial_guest_deletes_attendance(): void
    {
        [$club, $admin, , , $session] = $this->scenario(2);
        $guest = ClubClient::create(['club_id' => $club->id, 'name' => 'Гость']);
        $att = \App\Models\ClubGroupAttendance::create([
            'session_id' => $session->id, 'client_id' => $guest->id,
            'attended' => true, 'charged' => false, 'is_trial' => true, 'trial_amount' => 3000,
        ]);

        $this->actingAs($admin)
            ->delete(route('club.groupSessions.trialGuest.remove', [$session, $att]))
            ->assertRedirect();

        $this->assertDatabaseMissing('club_group_attendance', ['id' => $att->id]);
    }

    public function test_session_show_renders_with_freeze_and_guest_ui(): void
    {
        [$club, $admin, $group, $member, $session] = $this->scenario(2);
        \App\Models\ClubGroupMemberFreeze::create([
            'group_member_id' => $member->id,
            'freeze_from' => now()->subDays(3)->toDateString(),
            'freeze_until' => now()->toDateString(),
        ]);
        $guest = ClubClient::create(['club_id' => $club->id, 'name' => 'Гость']);
        \App\Models\ClubGroupAttendance::create([
            'session_id' => $session->id, 'client_id' => $guest->id,
            'attended' => true, 'charged' => false, 'is_trial' => true, 'trial_amount' => 2000,
        ]);

        $this->actingAs($admin)
            ->get(route('club.groupSessions.show', $session))
            ->assertOk()
            ->assertSee('Пробные гости')
            ->assertSee('заморожен');
    }

    public function test_group_show_renders_with_freeze_chip(): void
    {
        [, $admin, $group, $member] = $this->scenario(2);
        \App\Models\ClubGroupMemberFreeze::create([
            'group_member_id' => $member->id,
            'freeze_from' => now()->subDay()->toDateString(),
            'freeze_until' => now()->addDays(5)->toDateString(),
            'note' => 'отпуск',
        ]);

        $this->actingAs($admin)
            ->get(route('club.groups.show', $group))
            ->assertOk()
            ->assertSee('Заморозить')
            ->assertSee('отпуск');
    }

    public function test_conduct_blocked_before_session_end(): void
    {
        [, $admin, , $member, $session] = $this->scenario(2);
        // Переставим в будущее — занятие ещё не закончилось
        $session->update(['date' => now()->addDay()->toDateString()]);

        $this->actingAs($admin)->post(route('club.groupSessions.conduct', $session), [
            'attendance' => [$member->id => ['status' => 'charge']],
        ])->assertSessionHas('error');

        $this->assertSame('planned', $session->fresh()->status);
        $this->assertSame(2, $member->fresh()->remaining);
    }
}
