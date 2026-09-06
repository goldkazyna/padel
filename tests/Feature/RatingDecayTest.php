<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use App\Support\RatingDecay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Списание рейтинга за простой.
 *
 * Правила: первое списание на 60-й день без игры, дальше по −50 каждые 30
 * дней, ниже 1000 не опускаем, отсчёт для всех начался в день запуска.
 */
class RatingDecayTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();

        // Запуск системы — полгода назад, чтобы «день старта» не мешал
        // проверять сами правила.
        config(['rating.decay_started_at' => Carbon::now()->subMonths(6)->toDateString()]);
        $this->club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
    }

    private function player(int $rating, ?Carbon $lastPlayed = null, ?Carbon $decayedAt = null): User
    {
        return User::factory()->create([
            'rating' => $rating,
            'level' => RatingDecay::levelFor($rating),
            'last_played_at' => $lastPlayed,
            'rating_decayed_at' => $decayedAt,
        ]);
    }

    public function test_первое_списание_на_шестидесятый_день(): void
    {
        $this->assertFalse(RatingDecay::isDue(Carbon::now()->subDays(59), null));
        $this->assertTrue(RatingDecay::isDue(Carbon::now()->subDays(60), null));
    }

    public function test_дальше_каждые_тридцать_дней(): void
    {
        $played = Carbon::now()->subDays(200);

        // Списали 29 дней назад — рано.
        $this->assertFalse(RatingDecay::isDue($played, Carbon::now()->subDays(29)));
        // Тридцать прошло — пора.
        $this->assertTrue(RatingDecay::isDue($played, Carbon::now()->subDays(30)));
    }

    public function test_сыграл_после_списания_значит_снова_ждём_два_месяца(): void
    {
        $decayed = Carbon::now()->subDays(40);
        $played = Carbon::now()->subDays(35);

        // Прошло 35 дней с игры: для вернувшегося это мало.
        $this->assertFalse(RatingDecay::isDue($played, $decayed));
        $this->assertSame(25, RatingDecay::daysUntilDecay($played, $decayed));
    }

    public function test_ниже_тысячи_не_опускаем(): void
    {
        $this->assertSame(50, RatingDecay::amountFor(2000));
        $this->assertSame(30, RatingDecay::amountFor(1030));
        $this->assertSame(0, RatingDecay::amountFor(1000));
        $this->assertSame(0, RatingDecay::amountFor(900));
    }

    public function test_команда_списывает_и_пишет_историю(): void
    {
        $idle = $this->player(2000, Carbon::now()->subDays(70));
        $active = $this->player(2000, Carbon::now()->subDays(10));

        $this->artisan('rating:decay-inactive')->assertSuccessful();

        $this->assertSame(1950, (int) $idle->fresh()->rating);
        $this->assertSame('1.75', (string) $idle->fresh()->level, 'уровень пересчитан');
        $this->assertNotNull($idle->fresh()->rating_decayed_at);
        $this->assertSame(2000, (int) $active->fresh()->rating, 'играющего не трогаем');

        $row = RatingHistory::where('user_id', $idle->id)->first();
        $this->assertSame(-50, (int) $row->change);
        $this->assertSame(RatingHistory::REASON_DECAY, $row->reason);
    }

    public function test_второй_прогон_в_тот_же_день_не_списывает_дважды(): void
    {
        $idle = $this->player(2000, Carbon::now()->subDays(70));

        $this->artisan('rating:decay-inactive')->assertSuccessful();
        $this->artisan('rating:decay-inactive')->assertSuccessful();

        $this->assertSame(1950, (int) $idle->fresh()->rating);
        $this->assertSame(1, RatingHistory::where('user_id', $idle->id)->count());
    }

    public function test_dry_ничего_не_меняет(): void
    {
        $idle = $this->player(2000, Carbon::now()->subDays(70));

        $this->artisan('rating:decay-inactive', ['--dry' => true])->assertSuccessful();

        $this->assertSame(2000, (int) $idle->fresh()->rating);
        $this->assertSame(0, RatingHistory::where('user_id', $idle->id)->count());
    }

    public function test_дата_последней_игры_берётся_из_турнира(): void
    {
        $user = $this->player(2000);
        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'completed',
            'type' => 'americano',
            'start_date' => Carbon::now()->subDays(5),
        ]);
        $tournament->participants()->attach($user->id, ['status' => 'registered']);

        $this->artisan('rating:decay-inactive')->assertSuccessful();

        $this->assertNotNull($user->fresh()->last_played_at);
        $this->assertSame(2000, (int) $user->fresh()->rating, 'играл пять дней назад');
    }

    public function test_профиль_предупреждает_с_сорок_пятого_дня(): void
    {
        $user = $this->player(2000, Carbon::now()->subDays(46));
        Sanctum::actingAs($user);

        $block = $this->getJson('/api/mobile/profile')->assertOk()->json('inactivity');

        $this->assertTrue($block['warn']);
        $this->assertSame(46, $block['idle_days']);
        $this->assertSame(14, $block['days_until_decay']);
        $this->assertSame(50, $block['amount']);
        $this->assertFalse($block['decayed']);
    }

    public function test_профиль_молчит_пока_рано(): void
    {
        $user = $this->player(2000, Carbon::now()->subDays(44));
        Sanctum::actingAs($user);

        $block = $this->getJson('/api/mobile/profile')->assertOk()->json('inactivity');

        $this->assertFalse($block['warn']);
        $this->assertSame(44, $block['idle_days']);
    }

    public function test_у_кого_рейтинг_на_дне_не_пугаем(): void
    {
        // Списывать нечего — предупреждение было бы пустой угрозой.
        $user = $this->player(1000, Carbon::now()->subDays(90));
        Sanctum::actingAs($user);

        $block = $this->getJson('/api/mobile/profile')->assertOk()->json('inactivity');

        $this->assertFalse($block['warn']);
        $this->assertSame(0, $block['amount']);
    }

    public function test_отсчёт_не_раньше_дня_запуска(): void
    {
        // Система включилась вчера: у того, кто не играл год, простой — день.
        config(['rating.decay_started_at' => Carbon::now()->subDay()->toDateString()]);

        $user = $this->player(2000, Carbon::now()->subYear());

        $this->assertSame(1, RatingDecay::idleDays(Carbon::now()->subYear()));
        $this->assertFalse(RatingDecay::isDue(Carbon::now()->subYear(), null));

        $this->artisan('rating:decay-inactive')->assertSuccessful();
        $this->assertSame(2000, (int) $user->fresh()->rating);
    }
}
