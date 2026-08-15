<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\ClubGroup;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupSession;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Цена групповой брони: занятие × активные участники плюс пробные гости.
 *
 * Раньше занятие, созданное из раздела «Занятия», получало жёсткий ноль, и его
 * деньги не попадали в отчёты — там суммируется court_bookings.price.
 */
class GroupSessionPriceTest extends TestCase
{
    use RefreshDatabase;

    private function setup4(int $pricePerSession = 4500): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'Пробная суббота', 'price_per_session' => $pricePerSession,
        ]);

        return [$club, $admin, $court, $group];
    }

    private function addMembers(Club $club, ClubGroup $group, int $count): array
    {
        $made = [];
        for ($i = 1; $i <= $count; $i++) {
            $client = ClubClient::create([
                'club_id' => $club->id, 'name' => "Участник {$i}", 'phone' => '+7 701 000 00 0' . $i,
            ]);
            $made[] = ClubGroupMember::create([
                'group_id' => $group->id, 'client_id' => $client->id, 'status' => 'active',
            ]);
        }

        return $made;
    }

    private function createSession(User $admin, ClubGroup $group, Court $court, string $time = '10:00'): ClubGroupSession
    {
        $this->actingAs($admin)->post(route('club.groupSessions.store'), [
            'group_id' => $group->id,
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => $time,
            'slots' => 1,
        ])->assertRedirect();

        return ClubGroupSession::latest('id')->firstOrFail();
    }

    private function bookingPrice(ClubGroupSession $session): float
    {
        return (float) CourtBooking::findOrFail($session->court_booking_id)->price;
    }

    public function test_session_created_from_sessions_section_gets_price(): void
    {
        [$club, $admin, $court, $group] = $this->setup4();
        $this->addMembers($club, $group, 4);

        $session = $this->createSession($admin, $group, $court);

        // Раньше здесь был жёсткий ноль.
        $this->assertSame(18000.0, $this->bookingPrice($session), '4 500 × 4 участника');
    }

    public function test_group_without_members_keeps_zero(): void
    {
        [, $admin, $court, $group] = $this->setup4();

        $session = $this->createSession($admin, $group, $court);

        $this->assertSame(0.0, $this->bookingPrice($session));
    }

    public function test_adding_member_recalculates_planned_session(): void
    {
        [$club, $admin, $court, $group] = $this->setup4();
        $this->addMembers($club, $group, 4);
        $session = $this->createSession($admin, $group, $court);
        $this->assertSame(18000.0, $this->bookingPrice($session));

        // Пятый участник пришёл после того, как занятие уже создали.
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Пятый', 'phone' => '+7 701 000 00 05']);
        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id,
            'sessions' => 8,
        ])->assertRedirect();

        $this->assertSame(22500.0, $this->bookingPrice($session), '4 500 × 5 — цена догнала состав');
    }

    public function test_removing_member_recalculates_planned_session(): void
    {
        [$club, $admin, $court, $group] = $this->setup4();
        $members = $this->addMembers($club, $group, 4);
        $session = $this->createSession($admin, $group, $court);

        $this->actingAs($admin)
            ->delete(route('club.groups.members.destroy', [$group, $members[0]]))
            ->assertRedirect();

        $this->assertSame(13500.0, $this->bookingPrice($session), '4 500 × 3');
    }

    public function test_past_sessions_are_left_alone(): void
    {
        [$club, $admin, $court, $group] = $this->setup4();
        $this->addMembers($club, $group, 4);
        $session = $this->createSession($admin, $group, $court);

        // Занятие уже проведено — его деньги посчитаны в отчётах за период.
        $session->update(['status' => 'held', 'held_at' => now()]);
        CourtBooking::where('id', $session->court_booking_id)->update(['price' => 18000]);

        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Пятый', 'phone' => '+7 701 000 00 05']);
        $this->actingAs($admin)->post(route('club.groups.members.store', $group), [
            'client_id' => $client->id,
            'sessions' => 8,
        ])->assertRedirect();

        $this->assertSame(18000.0, $this->bookingPrice($session), 'прошедшее занятие не переписано');
    }

    public function test_trial_guest_adds_own_amount(): void
    {
        [$club, $admin, $court, $group] = $this->setup4();
        $this->addMembers($club, $group, 4);
        $session = $this->createSession($admin, $group, $court);

        $guest = ClubClient::create(['club_id' => $club->id, 'name' => 'Гость', 'phone' => '+7 701 000 00 09']);
        $this->actingAs($admin)->post(route('club.groupSessions.trialGuest', $session), [
            'client_id' => $guest->id,
            'trial_amount' => 5000,
        ])->assertRedirect();

        // На корте пятеро: четверо по абонементу и один пробный за свои деньги.
        $this->assertSame(23000.0, $this->bookingPrice($session), '4 500 × 4 + 5 000');
    }

    public function test_removing_trial_guest_takes_the_money_back(): void
    {
        [$club, $admin, $court, $group] = $this->setup4();
        $this->addMembers($club, $group, 4);
        $session = $this->createSession($admin, $group, $court);

        $guest = ClubClient::create(['club_id' => $club->id, 'name' => 'Гость', 'phone' => '+7 701 000 00 09']);
        $this->actingAs($admin)->post(route('club.groupSessions.trialGuest', $session), [
            'client_id' => $guest->id, 'trial_amount' => 5000,
        ]);
        $attendance = \App\Models\ClubGroupAttendance::where('session_id', $session->id)
            ->where('is_trial', true)->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('club.groupSessions.trialGuest.remove', [$session, $attendance]))
            ->assertRedirect();

        $this->assertSame(18000.0, $this->bookingPrice($session));
    }

    public function test_group_with_zero_price_stays_zero(): void
    {
        // Есть группы, где цена занятия не заполнена — там и считать нечего.
        [$club, $admin, $court, $group] = $this->setup4(pricePerSession: 0);
        $this->addMembers($club, $group, 3);

        $session = $this->createSession($admin, $group, $court);

        $this->assertSame(0.0, $this->bookingPrice($session));
    }
}
