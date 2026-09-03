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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Выплата тренеру за групповое занятие в расписании.
 *
 * У группы бывает своя ставка «за клиента». Пока занятие не провели, в
 * расписании стоит прикидка по составу; как провели — считаем по фактически
 * пришедшим, потому что именно так платит отчёт. Раньше расписание до конца
 * показывало прикидку, и цифры расходились.
 */
class ScheduleGroupCoachPayTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;
    private User $admin;
    private User $coachUser;
    private ClubGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->court = Court::create([
            'club_id' => $this->club->id,
            'name' => 'Корт 1',
            'open_time' => '08:00:00',
            'close_time' => '22:00:00',
            'slot_duration' => 60,
        ]);

        $this->coachUser = User::factory()->create(['name' => 'Bogdan']);
        ClubCoach::create([
            'club_id' => $this->club->id,
            'user_id' => $this->coachUser->id,
            'specialization' => 'Тренер',
            'hourly_rate' => 15000,
            'rate_group' => 12000,
        ]);

        $this->group = ClubGroup::create([
            'club_id' => $this->club->id,
            'name' => 'Начинающие, суббота',
            'coach_id' => $this->coachUser->id,
            'price_per_session' => 2250,
            'coach_price_per_client' => 2250,
            'capacity' => 8,
            'status' => 'active',
        ]);

        // Шесть человек в составе — прикидка до проведения считается по ним.
        foreach (range(1, 6) as $i) {
            $client = ClubClient::create([
                'club_id' => $this->club->id,
                'name' => "Игрок {$i}",
                'phone' => '+7 700 000 00 0' . $i,
            ]);
            ClubGroupMember::create([
                'group_id' => $this->group->id,
                'client_id' => $client->id,
                'status' => 'active',
            ]);
        }
    }

    private function booking(): CourtBooking
    {
        return CourtBooking::create([
            'court_id' => $this->court->id,
            'date' => '2026-08-08',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'client_name' => 'Группа: Начинающие, суббота',
            'status' => 'confirmed',
            'price' => 13500,
            'booking_type' => 'group',
            'booked_by' => $this->admin->id,
            'coach_id' => $this->coachUser->id,
        ]);
    }

    private function groupSession(CourtBooking $booking, string $status, int $attended = 0): ClubGroupSession
    {
        $session = ClubGroupSession::create([
            'group_id' => $this->group->id,
            'court_booking_id' => $booking->id,
            'court_id' => $this->court->id,
            'date' => '2026-08-08',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => $status,
        ]);

        $members = ClubGroupMember::where('group_id', $this->group->id)->take($attended)->get();
        foreach ($members as $member) {
            ClubGroupAttendance::create([
                'session_id' => $session->id,
                'group_member_id' => $member->id,
                'attended' => true,
                'charged' => true,
            ]);
        }

        return $session;
    }

    private function schedule()
    {
        return $this->actingAs($this->admin)
            ->get(route('club.courts.schedule', ['date' => '2026-08-08']));
    }

    public function test_проведённое_занятие_считается_по_пришедшим(): void
    {
        $booking = $this->booking();
        $this->groupSession($booking, 'held', attended: 5);

        // 2250 × 5 пришедших = 11 250, а не 2250 × 6 в составе. Ищем именно
        // бейдж выплаты («+ сумма»): 13 500 на слоте — это цена самой брони.
        $this->schedule()->assertOk()
            ->assertSee('+ 11 250')
            ->assertDontSee('+ 13 500');
    }

    public function test_непроведённое_занятие_показывает_прикидку_по_составу(): void
    {
        $booking = $this->booking();
        $this->groupSession($booking, 'planned');

        // 2250 × 6 в составе = 13 500: людей ещё не отмечали.
        $this->schedule()->assertOk()->assertSee('+ 13 500');
    }

    public function test_зафиксированная_сумма_главнее_всего(): void
    {
        $booking = $this->booking();
        $booking->update(['coach_price' => 9000]);
        $this->groupSession($booking, 'held', attended: 5);

        // Сумму заморозили при проведении — расписание её и показывает.
        $this->schedule()->assertOk()
            ->assertSee('+ 9 000')
            ->assertDontSee('+ 11 250');
    }
}
