<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\PlatformAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Цифры платформы за прошлое.
 */
class PlatformAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
    }

    private function court(): int
    {
        return DB::table('courts')->insertGetId([
            'club_id' => $this->club->id,
            'name' => 'Корт 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function booking(int $courtId, string $date, int $price): void
    {
        DB::table('court_bookings')->insert([
            'court_id' => $courtId,
            'date' => $date,
            'start_time' => '19:00',
            'end_time' => '20:00',
            'client_name' => 'Клиент',
            'client_phone' => '77770000000',
            'booked_by' => User::factory()->create()->id,
            'status' => 'confirmed',
            'price' => $price,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function completedTournament(string $date, string $type = 'americano'): Tournament
    {
        return Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => $type,
            'status' => 'completed',
            'start_date' => $date,
        ]);
    }

    public function test_participations_count_pairs_as_two_people(): void
    {
        $t = $this->completedTournament('2026-05-10 19:00:00', 'just_padel_it');
        [$a, $b] = User::factory()->count(2)->create();
        TournamentTeam::create([
            'tournament_id' => $t->id, 'player1_id' => $a->id,
            'player2_id' => $b->id, 'status' => 'approved',
        ]);

        $monthly = collect(app(PlatformAnalytics::class)->monthly())->firstWhere('month', '2026-05');

        $this->assertSame(2, $monthly['participations'], 'пара — это два человека');
        $this->assertSame(2, $monthly['active_players']);
    }

    public function test_only_completed_tournaments_count(): void
    {
        $this->completedTournament('2026-05-10 19:00:00');
        Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'open',
            'start_date' => '2026-05-20 19:00:00',
        ]);

        $monthly = collect(app(PlatformAnalytics::class)->monthly())->firstWhere('month', '2026-05');

        $this->assertSame(1, $monthly['tournaments'], 'открытый турнир ещё не проведён');
    }

    /** Один и тот же игрок в месяце считается один раз. */
    public function test_active_player_is_counted_once_per_month(): void
    {
        $user = User::factory()->create();
        foreach (['2026-05-01 19:00:00', '2026-05-15 19:00:00'] as $date) {
            $t = $this->completedTournament($date);
            $t->participants()->attach($user->id, ['status' => 'registered']);
        }

        $monthly = collect(app(PlatformAnalytics::class)->monthly())->firstWhere('month', '2026-05');

        $this->assertSame(2, $monthly['participations']);
        $this->assertSame(1, $monthly['active_players']);
    }

    public function test_retention_counts_those_who_played_again(): void
    {
        $stayed = User::factory()->create();
        $left = User::factory()->create();

        $first = $this->completedTournament('2026-05-10 19:00:00');
        $first->participants()->attach($stayed->id, ['status' => 'registered']);
        $first->participants()->attach($left->id, ['status' => 'registered']);

        $second = $this->completedTournament('2026-06-10 19:00:00');
        $second->participants()->attach($stayed->id, ['status' => 'registered']);

        $may = collect(app(PlatformAnalytics::class)->retention())->firstWhere('month', '2026-05');

        $this->assertSame(2, $may['first_time']);
        $this->assertSame(1, $may['returned']);
        $this->assertSame(50, $may['share']);
    }

    /** Итог участий должен сходиться с суммой по месяцам. */
    public function test_totals_include_pairs(): void
    {
        $t = $this->completedTournament('2026-05-10 19:00:00', 'just_padel_it');
        [$a, $b] = User::factory()->count(2)->create();
        TournamentTeam::create([
            'tournament_id' => $t->id, 'player1_id' => $a->id,
            'player2_id' => $b->id, 'status' => 'approved',
        ]);
        $t->participants()->attach(User::factory()->create()->id, ['status' => 'registered']);

        $analytics = app(PlatformAnalytics::class);
        $monthlySum = collect($analytics->monthly())->sum('participations');

        $this->assertSame(3, $analytics->totals()['participations']);
        $this->assertSame($monthlySum, $analytics->totals()['participations']);
    }

    /** Брони вперёд деньгами ещё не стали. */
    public function test_future_bookings_are_kept_apart(): void
    {
        $court = $this->court();
        $this->booking($court, now()->subDays(3)->toDateString(), 10000);
        $this->booking($court, now()->addDays(10)->toDateString(), 7000);

        $totals = app(PlatformAnalytics::class)->totals();

        $this->assertSame(1, $totals['bookings']);
        $this->assertSame(10000, $totals['revenue']);
        $this->assertSame(1, $totals['future_bookings']);
        $this->assertSame(7000, $totals['future_revenue']);
    }

    /** Тестовые клубы в отчёт для партнёров не идут. */
    public function test_test_clubs_are_excluded(): void
    {
        $test = Club::create(['name' => 'Тестовый', 'address' => 'Т', 'is_test' => true]);
        $t = Tournament::factory()->create([
            'club_id' => $test->id, 'status' => 'completed',
            'start_date' => '2026-05-10 19:00:00',
        ]);
        $t->participants()->attach(User::factory()->create()->id, ['status' => 'registered']);

        $analytics = app(PlatformAnalytics::class);

        $this->assertSame(0, $analytics->totals()['tournaments']);
        $this->assertSame(0, $analytics->totals()['participations']);
        $this->assertSame([], $analytics->byClub());
    }

    public function test_page_renders_for_super_admin(): void
    {
        $t = $this->completedTournament('2026-05-10 19:00:00');
        $t->participants()->attach(User::factory()->create()->id, ['status' => 'registered']);

        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Возвращаемость')
            ->assertSee('2026-05');
    }

    public function test_page_is_closed_for_others(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'club_admin']))
            ->get(route('admin.analytics'))
            ->assertForbidden();
    }
}
