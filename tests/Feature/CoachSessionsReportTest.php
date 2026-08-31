<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubCoach;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Reports\CoachesReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отчёт «Проведённые тренировки».
 *
 * Сплошной список по датам не отвечал на главный вопрос «сколько провёл и
 * заработал вот этот тренер»: приходилось фильтровать руками.
 */
class CoachSessionsReportTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->court = Court::create([
            'club_id' => $this->club->id, 'name' => 'Корт 1', 'price_per_hour' => 8000,
        ]);
    }

    private function coach(string $name, ?int $rateGroup = null, ?int $hourly = null): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'coach']);
        ClubCoach::create([
            'club_id' => $this->club->id,
            'user_id' => $user->id,
            'rate_group' => $rateGroup,
            'hourly_rate' => $hourly,
        ]);

        return $user;
    }

    private function booking(User $coach, string $type, string $date, float $price, ?float $coachPrice = null): CourtBooking
    {
        return CourtBooking::create([
            'court_id' => $this->court->id,
            'coach_id' => $coach->id,
            'booking_type' => $type,
            'status' => 'confirmed',
            'date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'price' => $price,
            'discount' => 0,
            'client_name' => 'Клиент',
            'booked_by' => $coach->id,
            'coach_price' => $coachPrice,
        ]);
    }

    private function sheet(): \App\Reports\ReportSheet
    {
        return app(CoachesReportService::class)->sessions(
            $this->club,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31')
        );
    }

    public function test_тренировки_разложены_по_тренерам_и_типам(): void
    {
        $anna = $this->coach('Анна Тренер', rateGroup: 4000);
        $boris = $this->coach('Борис Тренер', hourly: 6000);

        $this->booking($anna, 'group', '2026-08-10', 20000);
        $this->booking($anna, 'group', '2026-08-11', 20000);
        $this->booking($anna, 'individual', '2026-08-12', 15000, coachPrice: 7000);
        $this->booking($boris, 'individual', '2026-08-13', 12000, coachPrice: 5000);

        $rows = $this->sheet()->rows;
        $first = array_map(fn ($r) => (string) $r[0], $rows);

        // Сначала Анна с двумя разделами, потом Борис — по алфавиту.
        $this->assertSame('Анна Тренер', $first[0]);
        $this->assertContains('  Групповые', $first);
        $this->assertContains('  Итого групповые', $first);
        $this->assertContains('  Индивидуальные и прочие', $first);
        $this->assertContains('Итого Анна Тренер', $first);
        $this->assertContains('Борис Тренер', $first);

        $this->assertLessThan(
            array_search('Борис Тренер', $first, true),
            array_search('Итого Анна Тренер', $first, true),
            'блок тренера закрывается его итогом'
        );
    }

    public function test_итоги_считают_часы_оплату_и_заработок(): void
    {
        $anna = $this->coach('Анна Тренер', rateGroup: 4000);
        $this->booking($anna, 'group', '2026-08-10', 20000);
        $this->booking($anna, 'group', '2026-08-11', 20000);

        $sheet = $this->sheet();
        $rows = collect($sheet->rows);

        $groupTotal = $rows->firstWhere(0, '  Итого групповые');
        $this->assertSame('2 шт.', $groupTotal[1]);
        $this->assertEqualsWithDelta(2.0, $groupTotal[4], 0.01, 'два часа');
        $this->assertEqualsWithDelta(40000, $groupTotal[6], 0.01, 'оплата клиентов');
        // Групповая ставка 4000 × 1 час × 2 занятия.
        $this->assertEqualsWithDelta(8000, $groupTotal[7], 0.01, 'заработок тренера');

        $this->assertEqualsWithDelta(40000, $sheet->totals[6], 0.01);
        $this->assertEqualsWithDelta(8000, $sheet->totals[7], 0.01);
    }

    public function test_группа_со_ставкой_за_клиента_считается_по_людям(): void
    {
        // У пробных и разовых групп тренеру платят за пришедшего человека,
        // а не за час. Раньше отчёт этого не знал и брал часовую ставку:
        // занятие за 4 500 показывалось как 12 000.
        $anna = $this->coach('Анна Тренер', rateGroup: 12000);
        $booking = $this->booking($anna, 'group', '2026-08-10', 4500);

        $group = \App\Models\ClubGroup::create([
            'club_id' => $this->club->id,
            'name' => 'Пробная',
            'type' => \App\Models\ClubGroup::TYPE_TRIAL,
            'coach_id' => $anna->id,
            'coach_price_per_client' => 2250,
        ]);

        $session = \App\Models\ClubGroupSession::create([
            'group_id' => $group->id,
            'court_id' => $this->court->id,
            'court_booking_id' => $booking->id,
            'coach_id' => $anna->id,
            'date' => '2026-08-10',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'held',
        ]);

        foreach (['Первый', 'Второй'] as $name) {
            \App\Models\ClubGroupAttendance::create([
                'session_id' => $session->id,
                'client_name' => $name,
                'attended' => true,
            ]);
        }

        $row = collect($this->sheet()->rows)->firstWhere(0, '  Итого групповые');

        // 2250 × 2 пришедших, а не 12 000 × 1 час.
        $this->assertEqualsWithDelta(4500, $row[7], 0.01);
    }

    public function test_минусовая_оплата_показывается_как_есть(): void
    {
        // Скидка больше цены — ошибка ввода в брони. Отчёт её не прячет:
        // иначе клуб не узнает, что данные надо поправить.
        $anna = $this->coach('Анна Тренер');
        $b = $this->booking($anna, 'individual', '2026-08-10', 10000, coachPrice: 5000);
        $b->update(['discount' => 12000]);

        $rows = collect($this->sheet()->rows);
        $line = $rows->firstWhere(6, -2000.0);

        $this->assertNotNull($line, 'минус виден в строке');
        $this->assertEqualsWithDelta(-2000, $this->sheet()->totals[6], 0.01);
    }

    public function test_заголовки_и_выделение_строк(): void
    {
        $anna = $this->coach('Анна Тренер', rateGroup: 4000);
        $this->booking($anna, 'group', '2026-08-10', 20000);

        $sheet = $this->sheet();

        $this->assertSame(
            ['Дата / тренер', 'Время', 'Корт', 'Клиент', 'Часов', 'Тип', 'Оплата клиента', 'Тренеру'],
            $sheet->headings
        );
        // Имя тренера, заголовок раздела, его итог и итог тренера — жирные.
        $this->assertNotEmpty($sheet->boldRows);
        $this->assertContains(0, $sheet->boldRows);
    }
}
