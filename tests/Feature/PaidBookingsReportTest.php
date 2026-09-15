<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\User;
use App\Reports\FinanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отчёт «Оплаченные брони»: за что клуб получил деньги и кто это провёл.
 *
 * Групповые и турнирные брони в него не попадают: за группу платят пакетами
 * участников, за турнир — взносами, и в выручке по броням они дали бы суммы,
 * которых в кассе не было.
 */
class PaidBookingsReportTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private Court $court;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'C', 'address' => 'A']);
        $this->court = Court::create([
            'club_id' => $this->club->id, 'name' => 'К1',
            'open_time' => '08:00', 'close_time' => '22:00', 'slot_duration' => 60,
        ]);
        $this->manager = User::factory()->create(['role' => 'club_moderator', 'name' => 'Асель М.']);
        $this->manager->moderatorClubs()->attach($this->club->id);
    }

    private function booking(array $attrs = []): CourtBooking
    {
        return CourtBooking::create(array_merge([
            'court_id' => $this->court->id,
            'date' => '2026-09-05',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Иван Петров',
            'client_phone' => '77011112233',
            'price' => 10000,
            'status' => 'confirmed',
            'is_paid' => true,
            'payment_method' => 'kaspi',
            'booked_by' => $this->manager->id,
        ], $attrs));
    }

    /** Запись в журнале: кто завёл бронь (как это делает CRM). */
    private function logCreated(CourtBooking $booking, User $author, bool $fromApp = false): void
    {
        ActivityLog::create([
            'user_id' => $author->id,
            'club_id' => $this->club->id,
            'action' => 'created',
            'subject_type' => 'CourtBooking',
            'subject_id' => $booking->id,
            'description' => $fromApp
                ? "Бронь из приложения: {$booking->client_name}"
                : "Бронирование: {$booking->client_name}",
        ]);
    }

    /** Запись в журнале: кто отметил бронь оплаченной. */
    private function logPaid(CourtBooking $booking, User $author): void
    {
        ActivityLog::create([
            'user_id' => $author->id,
            'club_id' => $this->club->id,
            'action' => 'updated',
            'subject_type' => 'CourtBooking',
            'subject_id' => $booking->id,
            'description' => 'Редактирование брони',
            'changes' => ['is_paid' => true],
        ]);
    }

    private function sheet()
    {
        return app(FinanceReportService::class)->paidBookings(
            $this->club,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
        );
    }

    public function test_в_отчёт_идут_только_оплаченные_личные_брони(): void
    {
        $this->booking(['price' => 12000]);
        $this->booking(['price' => 8000, 'start_time' => '11:00', 'end_time' => '12:00', 'booking_type' => 'individual']);

        // Не должны попасть:
        $this->booking(['price' => 5000, 'is_paid' => false, 'start_time' => '12:00', 'end_time' => '13:00']);
        $this->booking(['price' => 40000, 'booking_type' => 'group', 'client_name' => 'Группа: Г10',
            'start_time' => '13:00', 'end_time' => '15:00']);
        $this->booking(['price' => 50000, 'booking_type' => 'tournament', 'client_name' => 'Турнир: Американо',
            'start_time' => '15:00', 'end_time' => '17:00']);

        $sheet = $this->sheet();
        $clients = array_column($sheet->rows, 3);

        $this->assertContains('Иван Петров', $clients);
        $this->assertNotContains('Группа: Г10', $clients);
        $this->assertNotContains('Турнир: Американо', $clients);
        $this->assertSame(20000.0, $sheet->totals[5], 'итог — только оплаченные корты');
    }

    public function test_видно_кто_из_менеджеров_провёл(): void
    {
        $other = User::factory()->create(['role' => 'club_moderator', 'name' => 'Дана К.']);
        $other->moderatorClubs()->attach($this->club->id);

        $this->logCreated($this->booking(['price' => 12000]), $this->manager);
        $this->logCreated(
            $this->booking(['price' => 3000, 'start_time' => '11:00', 'end_time' => '12:00']),
            $other,
        );

        $sheet = $this->sheet();

        // Менеджер — последняя колонка каждой строки с бронью.
        $this->assertSame('Асель М.', $sheet->rows[0][9]);
        $this->assertSame('Дана К.', $sheet->rows[1][9]);

        // Свод по менеджерам идёт под таблицей, самый результативный — первым.
        $summary = array_slice($sheet->rows, 2);
        $labels = array_column($summary, 0);
        $this->assertContains('По менеджерам', $labels);
        $this->assertSame('Асель М.', $summary[2][0]);
        $this->assertSame(12000.0, $summary[2][5]);
        $this->assertSame(1, $summary[2][6]);
    }

    public function test_онлайн_оплата_записана_на_приложение(): void
    {
        // Клиент заплатил сам через Plexy — менеджеру эти деньги не засчитываем.
        $payer = User::factory()->create(['role' => 'user', 'name' => 'Клиент из приложения']);
        $online = $this->booking(['price' => 26000, 'payment_method' => 'plexy', 'booked_by' => $payer->id]);
        $this->logCreated($online, $payer, fromApp: true);

        $this->logCreated(
            $this->booking(['price' => 10000, 'start_time' => '11:00', 'end_time' => '12:00']),
            $this->manager,
        );

        $sheet = $this->sheet();

        $this->assertSame('Приложение', $sheet->rows[0][9]);
        $this->assertSame('Асель М.', $sheet->rows[1][9]);

        // В своде «Приложение» — отдельной строкой, у менеджера только его бронь.
        $summary = array_slice($sheet->rows, 2);
        $byName = [];
        foreach ($summary as $row) {
            $byName[$row[0]] = $row[5];
        }
        $this->assertSame(26000.0, $byName['Приложение'] ?? null);
        $this->assertSame(10000.0, $byName['Асель М.'] ?? null);
    }

    public function test_привязка_карты_это_продажа(): void
    {
        $client = ClubClient::create([
            'club_id' => $this->club->id, 'name' => 'Айна Б.', 'phone' => '77015556677',
        ]);
        $type = ClubCardType::create([
            'club_id' => $this->club->id, 'name' => 'VIP 10',
            'kind' => 'visits', 'nominal' => 10, 'price' => 90000,
        ]);
        ClubCard::create([
            'club_id' => $this->club->id,
            'club_card_type_id' => $type->id,
            'club_client_id' => $client->id,
            'issued_by' => $this->manager->id,
            'code' => 'VIP000001',
            'balance' => 10,
            'initial_balance' => 10,
            'status' => 'active',
            'created_at' => '2026-09-10 12:00:00',
        ]);

        $this->logCreated($this->booking(['price' => 10000]), $this->manager);

        $sheet = $this->sheet();
        $cardRow = collect($sheet->rows)->first(fn ($r) => str_contains((string) $r[2], 'VIP 10'));

        $this->assertNotNull($cardRow, 'проданная карта должна быть в отчёте');
        $this->assertSame('Айна Б.', $cardRow[3]);
        $this->assertSame(90000.0, $cardRow[5]);
        $this->assertSame('Продажа карты', $cardRow[7]);
        $this->assertSame('VIP000001', $cardRow[8]);
        $this->assertSame('Асель М.', $cardRow[9], 'видно, кто продал');

        $this->assertSame(100000.0, $sheet->totals[5], 'бронь + карта');
    }

    public function test_бронь_по_клубной_карте_второй_раз_не_считаем(): void
    {
        // Деньги за неё клуб получил при продаже карты — иначе двойной счёт.
        $this->booking(['price' => 20000, 'payment_method' => 'club_card']);
        $this->booking(['price' => 10000, 'start_time' => '11:00', 'end_time' => '12:00']);

        $sheet = $this->sheet();

        $this->assertSame(10000.0, $sheet->totals[5]);
    }

    public function test_бронь_клиента_из_приложения_не_записывается_на_менеджера(): void
    {
        // Клиент забронировал себе сам: в booked_by лежит его аккаунт, но
        // сотрудником клуба он не является.
        $client = User::factory()->create(['role' => 'user', 'name' => 'Андрей Малафеев']);

        $fromApp = $this->booking(['price' => 26000, 'client_name' => 'Андрей Малафеев', 'booked_by' => $client->id]);
        $this->logCreated($fromApp, $client, fromApp: true);

        $this->logCreated(
            $this->booking(['price' => 10000, 'start_time' => '11:00', 'end_time' => '12:00']),
            $this->manager,
        );

        $sheet = $this->sheet();

        $this->assertSame('Приложение', $sheet->rows[0][9]);
        $this->assertSame('Асель М.', $sheet->rows[1][9]);
    }

    public function test_оплату_записываем_на_того_кто_её_отметил(): void
    {
        // Клиент забронировал в приложении, а заплатил наличными на ресепшене:
        // продажа за тем, кто провёл оплату в CRM.
        $client = User::factory()->create(['role' => 'user', 'name' => 'Ерасыл Б.']);
        $booking = $this->booking(['price' => 13000, 'payment_method' => 'cash', 'booked_by' => $client->id]);
        $this->logCreated($booking, $client, fromApp: true);
        $this->logPaid($booking, $this->manager);

        $sheet = $this->sheet();

        $this->assertSame('Асель М.', $sheet->rows[0][9]);
    }

    public function test_онлайн_оплата_ни_за_кем_не_числится(): void
    {
        // Plexy подтверждает шлюз, в журнале этого действия нет.
        $client = User::factory()->create(['role' => 'user', 'name' => 'Александр А']);
        $booking = $this->booking(['price' => 52000, 'payment_method' => 'plexy', 'booked_by' => $client->id]);
        $this->logCreated($booking, $client, fromApp: true);

        $this->assertSame('Приложение', $this->sheet()->rows[0][9]);
    }

    public function test_способ_оплаты_пишем_по_русски(): void
    {
        $this->booking(['payment_method' => 'kaspi']);
        $this->booking(['payment_method' => 'cash', 'start_time' => '11:00', 'end_time' => '12:00']);

        $sheet = $this->sheet();

        $this->assertSame('Kaspi', $sheet->rows[0][7]);
        $this->assertSame('Наличные', $sheet->rows[1][7]);
    }

    public function test_страница_отчётов_показывает_новый_отчёт(): void
    {
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($this->club->id);

        $this->actingAs($admin)
            ->get('/club/reports/extra')
            ->assertOk()
            ->assertSee('Оплаченные брони', false);

        $response = $this->actingAs($admin)
            ->get('/club/reports/extra/finance-paid?from=2026-09-01&to=2026-09-30')
            ->assertOk();

        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }
}
