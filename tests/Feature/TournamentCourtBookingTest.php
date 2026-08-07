<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Club;
use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
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
            ->assertSee('__tournaments', escape: false);
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
}
