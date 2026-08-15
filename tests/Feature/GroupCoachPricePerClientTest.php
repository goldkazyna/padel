<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\ClubCoach;
use App\Models\ClubGroup;
use App\Models\ClubGroupAttendance;
use App\Models\ClubGroupMember;
use App\Models\ClubGroupSession;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Services\GroupSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Цена тренеру за клиента: вместо часовой групповой ставки платим за каждого
 * пришедшего. Поле не заполнено — работает как раньше, по ставке.
 */
class GroupCoachPricePerClientTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Club, 1: User, 2: Court, 3: User} клуб, админ, корт, тренер */
    private function setupClub(?float $rateGroup = null): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $coach = User::factory()->create(['role' => 'coach']);
        ClubCoach::create([
            'club_id' => $club->id, 'user_id' => $coach->id, 'rate_group' => $rateGroup,
        ]);

        return [$club, $admin, $court, $coach];
    }

    /** Занятие с бронью и участниками; возвращает [занятие, участники]. */
    private function makeSession(Club $club, Court $court, ClubGroup $group, User $coach, int $members): array
    {
        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00',
            'client_name' => 'Группа: ' . $group->name,
            'status' => 'confirmed',
            'booked_by' => $coach->id,
            'price' => 0,
            'booking_type' => 'group',
            'coach_id' => $coach->id,
        ]);
        $session = ClubGroupSession::create([
            'group_id' => $group->id, 'court_id' => $court->id,
            'court_booking_id' => $booking->id,
            'date' => now()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
            'coach_id' => $coach->id, 'status' => 'planned',
        ]);

        $made = [];
        for ($i = 1; $i <= $members; $i++) {
            $client = ClubClient::create([
                'club_id' => $club->id, 'name' => "Участник {$i}", 'phone' => '+7 701 000 00 0' . $i,
            ]);
            $made[] = ClubGroupMember::create([
                'group_id' => $group->id, 'client_id' => $client->id, 'status' => 'active',
            ]);
        }

        return [$session, $made];
    }

    private function coachPrice(ClubGroupSession $session): ?float
    {
        $value = CourtBooking::findOrFail($session->court_booking_id)->coach_price;

        return $value === null ? null : (float) $value;
    }

    public function test_coach_is_paid_per_attending_client(): void
    {
        [$club, $admin, $court, $coach] = $this->setupClub(rateGroup: 8000);
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'G',
            'price_per_session' => 4500, 'coach_price_per_client' => 1500,
        ]);
        [$session, $members] = $this->makeSession($club, $court, $group, $coach, 4);

        // Трое пришли, один не был.
        $rows = [
            $members[0]->id => ['status' => 'charge'],
            $members[1]->id => ['status' => 'charge'],
            $members[2]->id => ['status' => 'charge'],
            $members[3]->id => ['status' => 'absent'],
        ];
        // Журнал берёт автора из auth(), поэтому входим под админом.
        $this->actingAs($admin);
        app(GroupSessionService::class)->conduct($session, $rows, $admin->id, $club);

        // 1 500 × 3 пришедших, часовая ставка 8 000 не используется.
        $this->assertSame(4500.0, $this->coachPrice($session->fresh()));
    }

    public function test_trial_guest_counts_as_attending(): void
    {
        [$club, $admin, $court, $coach] = $this->setupClub();
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'G',
            'price_per_session' => 4500, 'coach_price_per_client' => 1000,
        ]);
        [$session, $members] = $this->makeSession($club, $court, $group, $coach, 2);

        // Гость добавлен отдельно, участником группы он не является.
        $guest = ClubClient::create(['club_id' => $club->id, 'name' => 'Гость', 'phone' => '+7 701 000 00 09']);
        ClubGroupAttendance::create([
            'session_id' => $session->id, 'client_id' => $guest->id,
            'attended' => true, 'charged' => false, 'is_trial' => true, 'trial_amount' => 5000,
        ]);

        $rows = [
            $members[0]->id => ['status' => 'charge'],
            $members[1]->id => ['status' => 'charge'],
        ];
        // Журнал берёт автора из auth(), поэтому входим под админом.
        $this->actingAs($admin);
        app(GroupSessionService::class)->conduct($session, $rows, $admin->id, $club);

        // Двое своих + гость = трое на корте.
        $this->assertSame(3000.0, $this->coachPrice($session->fresh()));
    }

    public function test_without_the_field_hourly_rate_is_used(): void
    {
        [$club, $admin, $court, $coach] = $this->setupClub(rateGroup: 8000);
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'G', 'price_per_session' => 4500,
        ]);
        [$session, $members] = $this->makeSession($club, $court, $group, $coach, 3);

        $rows = [];
        foreach ($members as $m) {
            $rows[$m->id] = ['status' => 'charge'];
        }
        // Журнал берёт автора из auth(), поэтому входим под админом.
        $this->actingAs($admin);
        app(GroupSessionService::class)->conduct($session, $rows, $admin->id, $club);

        // Час занятия × 8 000 — старое поведение, число пришедших не влияет.
        $this->assertSame(8000.0, $this->coachPrice($session->fresh()));
    }

    public function test_nobody_came_means_nothing_to_pay(): void
    {
        [$club, $admin, $court, $coach] = $this->setupClub(rateGroup: 8000);
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'G',
            'price_per_session' => 4500, 'coach_price_per_client' => 1500,
        ]);
        [$session, $members] = $this->makeSession($club, $court, $group, $coach, 2);

        $rows = [
            $members[0]->id => ['status' => 'absent'],
            $members[1]->id => ['status' => 'absent'],
        ];
        // Журнал берёт автора из auth(), поэтому входим под админом.
        $this->actingAs($admin);
        app(GroupSessionService::class)->conduct($session, $rows, $admin->id, $club);

        $this->assertSame(0.0, $this->coachPrice($session->fresh()));
    }

    // ===== Прикидка на слоте расписания =====

    public function test_schedule_estimates_by_clients_not_by_hours(): void
    {
        [$club, $admin, $court, $coach] = $this->setupClub(rateGroup: 15000);
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'Тестовая',
            'price_per_session' => 1200, 'coach_price_per_client' => 1500,
        ]);
        // Середина следующей недели — иначе бронь на последний день недели
        // не попадает в недельную сетку под SQLite.
        $date = now()->startOfWeek(\Carbon\Carbon::MONDAY)->addWeek()->addDays(2)->toDateString();

        $this->actingAs($admin)->post(route('club.groupSessions.store'), [
            'group_id' => $group->id, 'court_id' => $court->id,
            'date' => $date, 'start_time' => '16:00', 'slots' => 1,
        ])->assertRedirect();

        // Занятие ещё не проведено — сумма прикидочная. Двое участников.
        $this->addMembersTo($club, $group, 2);
        \App\Models\CourtBooking::query()->update(['coach_id' => $coach->id]);

        $html = $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()->getContent();

        // 1 500 × 2 участника, а не часовая ставка 15 000.
        $this->assertStringContainsString('+ 3 000', $html);
        $this->assertStringNotContainsString('+ 15 000', $html);
    }

    public function test_schedule_falls_back_to_hourly_rate_without_the_field(): void
    {
        [$club, $admin, $court, $coach] = $this->setupClub(rateGroup: 15000);
        $group = ClubGroup::create([
            'club_id' => $club->id, 'name' => 'Обычная', 'price_per_session' => 1200,
        ]);
        $date = now()->startOfWeek(\Carbon\Carbon::MONDAY)->addWeek()->addDays(2)->toDateString();

        $this->actingAs($admin)->post(route('club.groupSessions.store'), [
            'group_id' => $group->id, 'court_id' => $court->id,
            'date' => $date, 'start_time' => '16:00', 'slots' => 1,
        ])->assertRedirect();

        $this->addMembersTo($club, $group, 2);
        \App\Models\CourtBooking::query()->update(['coach_id' => $coach->id]);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertSee('+ 15 000');
    }

    /** Добавить участников в существующую группу. */
    private function addMembersTo(Club $club, ClubGroup $group, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $client = ClubClient::create([
                'club_id' => $club->id, 'name' => "Участник {$i}", 'phone' => '+7 702 000 00 0' . $i,
            ]);
            ClubGroupMember::create([
                'group_id' => $group->id, 'client_id' => $client->id, 'status' => 'active',
            ]);
        }
    }

    // ===== Форма =====

    public function test_empty_field_means_not_set_and_not_zero(): void
    {
        [, $admin] = $this->setupClub();

        $this->actingAs($admin)->post(route('club.groups.store'), [
            'name' => 'Без ставки за клиента',
            'price_per_session' => 4500,
            'coach_price_per_client' => '',
        ])->assertRedirect();

        // Ноль означал бы «тренеру не платим», а пусто — «платим как раньше».
        $this->assertNull(ClubGroup::firstOrFail()->coach_price_per_client);
    }

    public function test_field_is_saved_and_can_be_changed(): void
    {
        [$club, $admin] = $this->setupClub();

        $this->actingAs($admin)->post(route('club.groups.store'), [
            'name' => 'Со ставкой',
            'price_per_session' => 4500,
            'coach_price_per_client' => 1500,
        ])->assertRedirect();

        $group = ClubGroup::firstOrFail();
        $this->assertSame('1500.00', $group->coach_price_per_client);

        $this->actingAs($admin)->put(route('club.groups.update', $group), [
            'name' => 'Со ставкой',
            'price_per_session' => 4500,
            'coach_price_per_client' => 2000,
        ])->assertRedirect();

        $this->assertSame('2000.00', $group->fresh()->coach_price_per_client);
    }

    public function test_negative_value_is_rejected(): void
    {
        [, $admin] = $this->setupClub();

        $this->actingAs($admin)->post(route('club.groups.store'), [
            'name' => 'Минус',
            'coach_price_per_client' => -100,
        ])->assertSessionHasErrors('coach_price_per_client');

        $this->assertSame(0, ClubGroup::count());
    }
}
