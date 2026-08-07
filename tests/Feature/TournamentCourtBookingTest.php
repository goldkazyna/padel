<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Club;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\ClubCardService;
use App\Services\TournamentBookingPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentCourtBookingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Админ, создавший брони через makeBooking() — booked_by NOT NULL,
     * поэтому запоминаем его при setupTournament(), т.к. многие тесты
     * деструктурируют результат, отбрасывая $admin.
     */
    private User $bookingAdmin;

    /** Клуб, админ, корт и турнир с ценой — общая заготовка для тестов. */
    private function setupTournament(float $price = 20000): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $this->bookingAdmin = $admin;
        $court = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 1', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $tournament = Tournament::create([
            'club_id' => $club->id,
            'name' => 'Американо',
            'type' => 'americano',
            'status' => 'open',
            'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 16,
            'price' => $price,
        ]);

        return [$club, $admin, $court, $tournament];
    }

    public function test_booking_belongs_to_tournament(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Турнир: Американо',
            'status' => 'confirmed',
            'price' => 0,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
            'booked_by' => $admin->id,
        ]);

        $this->assertSame($tournament->id, $booking->fresh()->tournament->id);
        $this->assertTrue($tournament->courtBookings->contains($booking));
    }

    public function test_deleting_tournament_keeps_booking(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Турнир: Американо',
            'status' => 'confirmed',
            'price' => 50000,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
            'booked_by' => $admin->id,
        ]);

        $tournament->delete();

        $booking->refresh();
        $this->assertNull($booking->tournament_id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('50000.00', $booking->price);
    }

    /** Записать в турнир $count оплативших и $pending заявок на модерации. */
    private function addParticipants(Tournament $tournament, int $count, int $pending = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'registered',
            ]);
        }
        for ($i = 0; $i < $pending; $i++) {
            TournamentParticipant::create([
                'tournament_id' => $tournament->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Создать турнирную бронь на корте в указанное время.
     * booked_by NOT NULL в БД — берём админа, сохранённого в setupTournament().
     */
    private function makeBooking(Court $court, Tournament $tournament, string $date, string $start): CourtBooking
    {
        return CourtBooking::create([
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => $start,
            'end_time' => '23:00',
            'client_name' => 'Турнир: ' . $tournament->name,
            'status' => 'confirmed',
            'price' => 0,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
            'booked_by' => $this->bookingAdmin->id,
        ]);
    }

    public function test_total_is_price_times_paid_participants(): void
    {
        [, , , $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);

        $total = app(TournamentBookingPriceService::class)->totalForDate($tournament->fresh());

        $this->assertSame(100000.0, $total);
    }

    public function test_pending_participants_do_not_count(): void
    {
        [, , , $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5, pending: 3);

        $total = app(TournamentBookingPriceService::class)->totalForDate($tournament->fresh());

        $this->assertSame(100000.0, $total);
    }

    public function test_single_court_gets_full_sum(): void
    {
        [, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');

        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);

        $this->assertSame('100000.00', $booking->fresh()->price);
    }

    public function test_sum_splits_evenly_between_four_courts(): void
    {
        [$club, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $bookings = [$this->makeBooking($court, $tournament, $date, '10:00')];
        for ($i = 2; $i <= 4; $i++) {
            $extra = Court::create([
                'club_id' => $club->id, 'name' => "Корт {$i}", 'is_active' => true,
                'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
            ]);
            $bookings[] = $this->makeBooking($extra, $tournament, $date, '10:00');
        }

        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);

        foreach ($bookings as $b) {
            $this->assertSame('25000.00', $b->fresh()->price);
        }
    }

    public function test_remainder_goes_to_first_booking(): void
    {
        [$club, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $first = $this->makeBooking($court, $tournament, $date, '10:00');
        $rest = [];
        for ($i = 2; $i <= 3; $i++) {
            $extra = Court::create([
                'club_id' => $club->id, 'name' => "Корт {$i}", 'is_active' => true,
                'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
            ]);
            $rest[] = $this->makeBooking($extra, $tournament, $date, '11:00');
        }

        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);

        // 100 000 / 3 = 33 333,33 — остаток достаётся первой по времени броне.
        $this->assertSame('33333.34', $first->fresh()->price);
        $this->assertSame('33333.33', $rest[0]->fresh()->price);
        $this->assertSame('33333.33', $rest[1]->fresh()->price);
        $sum = collect([$first, ...$rest])->sum(fn ($b) => (float) $b->fresh()->price);
        $this->assertSame(100000.0, round($sum, 2));
    }

    public function test_cancelled_booking_is_excluded_from_split(): void
    {
        [$club, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $kept = $this->makeBooking($court, $tournament, $date, '10:00');
        $second = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 2', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $cancelled = $this->makeBooking($second, $tournament, $date, '10:00');
        $cancelled->update(['status' => 'cancelled']);

        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);

        $this->assertSame('100000.00', $kept->fresh()->price);
    }

    public function test_sync_without_bookings_returns_false(): void
    {
        [, , , $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);

        $changed = app(TournamentBookingPriceService::class)
            ->syncForDate($tournament->fresh(), now()->addDay()->toDateString());

        $this->assertFalse($changed);
    }

    public function test_schedule_page_exposes_tournaments(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertSee('Американо')
            ->assertSee('__tournaments', escape: false)
            ->assertSee('bookTournamentSelectWrap', escape: false)
            ->assertSee('name="tournament_id"', escape: false);
    }

    public function test_completed_tournament_is_not_offered(): void
    {
        [, $admin, , $tournament] = $this->setupTournament(20000);
        $tournament->update(['status' => 'completed', 'name' => 'Прошедший турнир']);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertDontSee('Прошедший турнир');
    }

    public function test_opening_schedule_recalculates_price(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $date = now()->addDay()->toDateString();
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');

        // Игроки записались уже после того, как корт забронировали.
        $this->addParticipants($tournament, 5);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk();

        $this->assertSame('100000.00', $booking->fresh()->price);
    }

    public function test_store_booking_links_tournament_and_sets_price(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => $date,
            'start_time' => '10:00',
            'slots' => 1,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
        ])->assertRedirect();

        $booking = CourtBooking::where('tournament_id', $tournament->id)->first();
        $this->assertNotNull($booking);
        $this->assertSame('tournament', $booking->booking_type);
        $this->assertSame('100000.00', $booking->price);
        $this->assertSame('Турнир: Американо', $booking->client_name);
        $this->assertNull($booking->payment_method);
    }

    public function test_store_booking_with_repeat_calculates_each_date_separately(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => $date,
            'start_time' => '10:00',
            'slots' => 1,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
            'repeat' => 'daily',
            'repeat_until' => 'week',
        ])->assertRedirect();

        $bookings = CourtBooking::where('tournament_id', $tournament->id)->orderBy('date')->get();
        // Каждая дата — свой набор броней турнира на эту дату, сумма считается независимо.
        $this->assertGreaterThan(1, $bookings->count());
        foreach ($bookings as $booking) {
            $this->assertSame('100000.00', $booking->price);
        }
    }

    /**
     * Регресс: tournament_id чужого клуба не должен сохраняться в бронь —
     * иначе последующий пересчёт syncForBooking() задел бы чужие брони.
     */
    public function test_foreign_tournament_id_is_not_saved(): void
    {
        [, $admin, $court] = $this->setupTournament(20000); // клуб A
        [, , , $foreignTournament] = $this->setupTournament(30000); // клуб B
        $date = now()->addDay()->toDateString();

        $response = $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => $date,
            'start_time' => '10:00',
            'slots' => 1,
            'booking_type' => 'tournament',
            'tournament_id' => $foreignTournament->id,
        ]);
        $response->assertRedirect();

        $booking = CourtBooking::where('court_id', $court->id)->first();
        $this->assertNotNull($booking);
        $this->assertNull($booking->tournament_id);
        $this->assertSame('Турнир', $booking->client_name);
        // Пересчёт по чужому турниру не запускался — броней с его id быть не должно.
        $this->assertSame(0, CourtBooking::where('tournament_id', $foreignTournament->id)->count());
    }

    /** Регресс: турнирная бронь не должна гасить переданный сертификат — цена всё равно от турнира. */
    public function test_tournament_booking_does_not_consume_certificate(): void
    {
        [$club, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $certificate = Certificate::create([
            'club_id' => $club->id,
            'type' => Certificate::TYPE_GENERIC,
            'number' => 'CERT-001',
            'value_type' => Certificate::VALUE_AMOUNT,
            'amount' => 5000,
        ]);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => $date,
            'start_time' => '10:00',
            'slots' => 1,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
            'certificate_id' => $certificate->id,
        ])->assertRedirect();

        $booking = CourtBooking::where('tournament_id', $tournament->id)->first();
        $this->assertNotNull($booking);
        $this->assertNull($booking->certificate_id);
        $this->assertNull($certificate->fresh()->used_at);
    }

    /** Регресс: без tournament_id турнирную бронь создать нельзя — иначе цена навсегда останется 0. */
    public function test_tournament_booking_without_tournament_id_is_rejected(): void
    {
        [, $admin, $court] = $this->setupTournament(20000);
        $date = now()->addDay()->toDateString();

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => $date,
            'start_time' => '10:00',
            'slots' => 1,
            'booking_type' => 'tournament',
        ])->assertSessionHasErrors('tournament_id');

        $this->assertSame(0, CourtBooking::where('court_id', $court->id)->count());
    }

    public function test_changing_tournament_recalculates_both_sets(): void
    {
        [$club, $admin, $court, $first] = $this->setupTournament(20000);
        $this->addParticipants($first, 5);
        $second = Tournament::create([
            'club_id' => $club->id, 'name' => 'Мексикано', 'type' => 'mexicano',
            'status' => 'open', 'start_date' => now()->addDay()->toDateString(),
            'max_participants' => 16, 'price' => 10000,
        ]);
        $this->addParticipants($second, 4);

        $date = now()->addDay()->toDateString();
        $booking = $this->makeBooking($court, $first, $date, '10:00');
        app(TournamentBookingPriceService::class)->syncForDate($first->fresh(), $date);
        $this->assertSame('100000.00', $booking->fresh()->price);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
            'tournament_id' => $second->id,
        ])->assertRedirect();

        $this->assertSame($second->id, $booking->fresh()->tournament_id);
        $this->assertSame('40000.00', $booking->fresh()->price);
    }

    public function test_cancelling_one_booking_raises_the_others(): void
    {
        [$club, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $kept = $this->makeBooking($court, $tournament, $date, '10:00');
        $second = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 2', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $dropped = $this->makeBooking($second, $tournament, $date, '10:00');
        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);
        $this->assertSame('50000.00', $kept->fresh()->price);

        $this->actingAs($admin)
            ->post(route('club.courts.cancelBooking', $dropped), ['reason' => 'Отмена для теста'])
            ->assertRedirect();

        $this->assertSame('100000.00', $kept->fresh()->price);
    }

    /**
     * Регресс: tournament_id чужого клуба нельзя привязать при редактировании —
     * иначе последующий пересчёт затронул бы чужие брони (та же дыра, что была в book()).
     */
    public function test_updating_to_foreign_tournament_id_is_not_saved(): void
    {
        [, $admin, $court] = $this->setupTournament(20000); // клуб A
        [, , , $foreignTournament] = $this->setupTournament(30000); // клуб B
        $date = now()->addDay()->toDateString();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Иван Иванов',
            'client_phone' => '77771112233',
            'status' => 'confirmed',
            'price' => 15000,
            'booking_type' => 'individual',
            'booked_by' => $this->bookingAdmin->id,
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
            'tournament_id' => $foreignTournament->id,
        ])->assertRedirect();

        $booking->refresh();
        $this->assertNull($booking->tournament_id);
        $this->assertSame('Турнир', $booking->client_name);
        $this->assertSame(0, CourtBooking::where('tournament_id', $foreignTournament->id)->count());
    }

    /** Регресс: скидка и кастомная цена не должны переживать превращение брони в турнирную. */
    public function test_converting_to_tournament_clears_discount(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Иван Иванов',
            'client_phone' => '77771112233',
            'status' => 'confirmed',
            'price' => 15000,
            'discount' => 5000,
            'booking_type' => 'individual',
            'booked_by' => $this->bookingAdmin->id,
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
        ])->assertRedirect();

        $booking->refresh();
        $this->assertSame('tournament', $booking->booking_type);
        $this->assertSame('0.00', $booking->discount);
        $this->assertSame('100000.00', $booking->price);
    }

    /** Регресс: превратить бронь в турнирную без выбора турнира нельзя — иначе цена навсегда останется 0. */
    public function test_updating_to_tournament_without_tournament_id_is_rejected(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $date = now()->addDay()->toDateString();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Иван Иванов',
            'client_phone' => '77771112233',
            'status' => 'confirmed',
            'price' => 15000,
            'booking_type' => 'individual',
            'booked_by' => $this->bookingAdmin->id,
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
        ])->assertSessionHasErrors('tournament_id');

        $this->assertSame('individual', $booking->fresh()->booking_type);
    }

    /**
     * Блокер: турнир при завершении переходит в 'completed' и пропадал из селекта,
     * из-за чего его бронь становилась нередактируемой навсегда.
     */
    public function test_completed_tournament_stays_in_picker_for_its_own_booking(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');
        $tournament->update(['status' => 'completed']);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk()
            ->assertSee('<option value="' . $tournament->id . '">Американо', escape: false);

        // И бронь по-прежнему сохраняется — например, отметкой «обработана».
        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
            'is_processed' => 1,
            'comment' => 'Проверено',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $booking->refresh();
        $this->assertTrue((bool) $booking->is_processed);
        $this->assertSame($tournament->id, $booking->tournament_id);
        $this->assertSame('Проверено', $booking->comment);
    }

    /** Блокер: бронь завершённого турнира видна и в недельном виде. */
    public function test_completed_tournament_stays_in_picker_in_week_view(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        // Среда — детерминированная дата: не зависит от дня прогона и не попадает
        // на границу недели (с now()->addDay() тест падал по воскресеньям).
        $date = now()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(2)->toDateString();
        $this->makeBooking($court, $tournament, $date, '10:00');
        $tournament->update(['status' => 'completed']);

        $this->actingAs($admin)
            ->get(route('club.courts.scheduleWeek', ['date' => $date]))
            ->assertOk()
            ->assertSee('<option value="' . $tournament->id . '">Американо', escape: false);
    }

    /**
     * Блокер: на проде уже есть брони с booking_type='tournament' и tournament_id=NULL
     * (кнопка «Турнир» существовала до привязки к турниру). Их нужно уметь сохранять
     * как есть, не обнуляя ручную цену и не требуя выбрать турнир.
     */
    public function test_legacy_tournament_booking_without_tournament_id_is_saved_as_is(): void
    {
        [, $admin, $court] = $this->setupTournament(20000);
        $date = now()->addDay()->toDateString();

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Турнир выходного дня',
            'status' => 'confirmed',
            'price' => 30000,
            'payment_method' => 'cash',
            'is_paid' => true,
            'booking_type' => 'tournament',
            'tournament_id' => null,
            'booked_by' => $this->bookingAdmin->id,
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
            'is_processed' => 1,
            'comment' => 'Оплачено наличными',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $booking->refresh();
        $this->assertSame('30000.00', $booking->price);
        $this->assertSame('cash', $booking->payment_method);
        $this->assertSame('Турнир выходного дня', $booking->client_name);
        $this->assertTrue((bool) $booking->is_processed);
        $this->assertSame('Оплачено наличными', $booking->comment);
    }

    /** Открытие прошедшей даты не должно менять выручку закрытого периода. */
    public function test_past_date_is_not_recalculated_on_view(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->subMonth()->toDateString();
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');
        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);
        $this->assertSame('100000.00', $booking->fresh()->price);

        // Из турнира убрали участника — прошлое это менять не должно.
        TournamentParticipant::where('tournament_id', $tournament->id)->first()->delete();

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('club.courts.scheduleWeek', ['date' => $date]))
            ->assertOk();

        $this->assertSame('100000.00', $booking->fresh()->price);
    }

    /** Сегодняшняя дата пересчитывается (граница «прошлое/не прошлое»). */
    public function test_today_is_recalculated_on_view(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $date = now()->toDateString();
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');
        $this->addParticipants($tournament, 5);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk();

        $this->assertSame('100000.00', $booking->fresh()->price);
    }

    /** Турнир в статусе 'closed' (день игры) должен предлагаться в списке. */
    public function test_closed_tournament_is_offered(): void
    {
        [, $admin, , $tournament] = $this->setupTournament(20000);
        $tournament->update(['status' => 'closed', 'name' => 'Турнир сегодня']);

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('Турнир сегодня');
    }

    /**
     * Клубная карта не должна переживать превращение брони в турнирную:
     * крон cards:charge-due не смотрит на тип брони и списал бы часы клиента.
     */
    public function test_tournament_booking_drops_club_card(): void
    {
        [$club, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван Иванов', 'phone' => '77770001122']);
        $type = ClubCardType::create([
            'club_id' => $club->id, 'name' => '10 пос.', 'code_prefix' => 'VIS',
            'kind' => 'visits', 'nominal' => 10,
        ]);
        $card = (new ClubCardService())->issue($client, $type);

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Иван Иванов',
            'client_phone' => '77770001122',
            'status' => 'confirmed',
            'price' => 10000,
            'booking_type' => 'individual',
            'payment_method' => 'club_card',
            'club_card_id' => $card->id,
            'booked_by' => $this->bookingAdmin->id,
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
        ])->assertRedirect();

        $booking->refresh();
        $this->assertNull($booking->club_card_id, 'турнирная бронь не должна нести клубную карту');
        $this->assertNull($booking->card_charged_at);
        $this->assertSame('100000.00', $booking->price);
    }

    /**
     * Недельное расписание: список турниров и живой пересчёт работают так же.
     * Время заморожено на субботу, бронь — на воскресенье: это верхняя граница
     * недели, на которой раньше терялись брони последнего дня.
     */
    public function test_week_schedule_exposes_tournaments_and_recalculates(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-08-08 10:00:00'));
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $date = now()->addDay()->toDateString();
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');
        $this->addParticipants($tournament, 5);

        $this->actingAs($admin)
            ->get(route('club.courts.scheduleWeek', ['date' => $date]))
            ->assertOk()
            ->assertSee('Американо')
            ->assertSee('__tournaments', escape: false)
            ->assertSee('bookTournamentSelectWrap', escape: false)
            ->assertSee('name="tournament_id"', escape: false);

        $this->assertSame('100000.00', $booking->fresh()->price);
    }

    /** Бронь на выключенном корте не видна в расписании — долю она забирать не должна. */
    public function test_booking_on_inactive_court_does_not_take_a_share(): void
    {
        [$club, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $visible = $this->makeBooking($court, $tournament, $date, '10:00');
        $off = Court::create([
            'club_id' => $club->id, 'name' => 'Выключенный', 'is_active' => false,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $hidden = $this->makeBooking($off, $tournament, $date, '10:00');

        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);

        $this->assertSame('100000.00', $visible->fresh()->price, 'вся сумма у видимой брони');
        $this->assertSame('0.00', $hidden->fresh()->price, 'невидимая бронь не дублирует деньги');
    }

    /** Командный турнир: счётчик считает пары — имена тоже должны браться из пар. */
    public function test_team_tournament_lists_pair_players(): void
    {
        [$club, , , $tournament] = $this->setupTournament(20000);
        $tournament->update(['type' => 'team']);
        // У части игроков name пустой (регистрация по телефону) — имя в first/last_name.
        $p1 = User::factory()->create(['name' => '', 'first_name' => 'Иван', 'last_name' => 'Петров']);
        $p2 = User::factory()->create(['name' => 'Пётр Сидоров']);
        TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $p1->id,
            'player2_id' => $p2->id,
            'status' => TournamentTeam::STATUS_APPROVED,
        ]);

        $data = app(TournamentBookingPriceService::class)
            ->pickerData($club, [now()->addDay()->toDateString()]);

        $this->assertSame(2, $data[$tournament->id]['paid_count']);
        $this->assertSame(['Иван Петров', 'Пётр Сидоров'], $data[$tournament->id]['participants']);
        $this->assertSame(40000.0, $data[$tournament->id]['total']);
    }

    /** Сообщение об успехе должно показывать реальную цену, а не 0 ₸. */
    public function test_success_message_shows_calculated_price(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);

        $this->actingAs($admin)->post(route('club.courts.book', $court), [
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'slots' => 1,
            'booking_type' => 'tournament',
            'tournament_id' => $tournament->id,
        ])->assertSessionHas('success', fn ($message) => str_contains($message, '100 000'));
    }

    /**
     * Регресс: правка брони без tournament_id в запросе (окно открыто из панели
     * «Необработанные заявки» для брони не с видимой даты) не должна тихо рвать
     * привязку — иначе доли перераспределяются, а деньги задваиваются.
     */
    public function test_missing_tournament_id_keeps_existing_link(): void
    {
        [$club, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $kept = $this->makeBooking($court, $tournament, $date, '10:00');
        $second = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 2', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $edited = $this->makeBooking($second, $tournament, $date, '10:00');
        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);
        $this->assertSame('50000.00', $kept->fresh()->price);

        // Пустой tournament_id — так отправляет форма, когда селект нечем заполнить.
        $this->actingAs($admin)->put(route('club.courts.updateBooking', $edited), [
            'booking_type' => 'tournament',
            'tournament_id' => '',
            'is_processed' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($tournament->id, $edited->fresh()->tournament_id, 'привязка сохранилась');
        $this->assertSame('50000.00', $kept->fresh()->price, 'доли не перераспределились');
        $this->assertSame('50000.00', $edited->fresh()->price);
    }

    /** Отвязка турнира делается сменой типа брони — этот путь работать обязан. */
    public function test_changing_type_away_unlinks_tournament(): void
    {
        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'individual',
            'client_name' => 'Иван Иванов',
            'client_phone' => '77771112233',
            'payment_method' => 'cash',
            'is_paid' => 1,
        ])->assertRedirect();

        $this->assertNull($booking->fresh()->tournament_id);
        $this->assertSame('individual', $booking->fresh()->booking_type);
    }

    /** Граница «прошлое/не прошлое» живёт в таймзоне расписания, а не в UTC. */
    public function test_past_boundary_uses_schedule_timezone(): void
    {
        // 01:00 по Алматы = 20:00 предыдущего дня по UTC: «сегодня» должно
        // остаться алматинским, иначе вчерашний день пересчитается.
        $this->travelTo(\Carbon\Carbon::parse('2026-08-10 20:00:00', 'UTC'));
        $almatyToday = now(config('app.schedule_timezone', 'Asia/Almaty'))->toDateString();
        $this->assertSame('2026-08-11', $almatyToday, 'проверяем именно ночное окно');

        [, $admin, $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        // Вчерашний по Алматы день — уже прошлое.
        $date = '2026-08-10';
        $booking = $this->makeBooking($court, $tournament, $date, '10:00');
        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);
        $this->assertSame('100000.00', $booking->fresh()->price);

        TournamentParticipant::where('tournament_id', $tournament->id)->first()->delete();

        $this->actingAs($admin)
            ->get(route('club.courts.schedule', ['date' => $date]))
            ->assertOk();

        $this->assertSame('100000.00', $booking->fresh()->price);
    }

    /** Легаси-бронь: оплата клубной картой не остаётся без карты. */
    public function test_legacy_tournament_booking_drops_card_payment_together_with_card(): void
    {
        [$club, $admin, $court] = $this->setupTournament(20000);
        $client = ClubClient::create(['club_id' => $club->id, 'name' => 'Иван Иванов', 'phone' => '77770001122']);
        $type = ClubCardType::create([
            'club_id' => $club->id, 'name' => '10 пос.', 'code_prefix' => 'VIS',
            'kind' => 'visits', 'nominal' => 10,
        ]);
        $card = (new ClubCardService())->issue($client, $type);

        $booking = CourtBooking::create([
            'court_id' => $court->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'client_name' => 'Турнир выходного дня',
            'status' => 'confirmed',
            'price' => 30000,
            'payment_method' => 'club_card',
            'club_card_id' => $card->id,
            'booking_type' => 'tournament',
            'booked_by' => $this->bookingAdmin->id,
        ]);

        $this->actingAs($admin)->put(route('club.courts.updateBooking', $booking), [
            'booking_type' => 'tournament',
            'is_processed' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $booking->refresh();
        $this->assertNull($booking->club_card_id);
        $this->assertNull($booking->payment_method, 'оплата картой без карты — противоречие');
        $this->assertSame('30000.00', $booking->price, 'ручная цена сохранилась');
    }

    /** Отмена брони из мобильного приложения тоже пересчитывает доли. */
    public function test_mobile_cancel_recalculates_shares(): void
    {
        [$club, , $court, $tournament] = $this->setupTournament(20000);
        $this->addParticipants($tournament, 5);
        $date = now()->addDay()->toDateString();

        $kept = $this->makeBooking($court, $tournament, $date, '10:00');
        $second = Court::create([
            'club_id' => $club->id, 'name' => 'Корт 2', 'is_active' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'slot_duration' => 60,
        ]);
        $dropped = $this->makeBooking($second, $tournament, $date, '10:00');
        app(TournamentBookingPriceService::class)->syncForDate($tournament->fresh(), $date);
        $this->assertSame('50000.00', $kept->fresh()->price);

        $this->actingAs($this->bookingAdmin, 'sanctum')
            ->postJson("/api/mobile/courts/bookings/{$dropped->id}/cancel")
            ->assertOk();

        $this->assertSame('100000.00', $kept->fresh()->price);
    }
}
