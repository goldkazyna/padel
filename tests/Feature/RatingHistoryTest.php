<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use App\Support\RatingTrend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Вся динамика рейтинга.
 *
 * В карточке профиля видно последние десять точек, а дальше история
 * обрывалась. Тот же список, но целиком, отдаёт /profile/rating-history —
 * и считается он одним кодом, иначе однажды получим два разных графика.
 */
class RatingHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['rating' => 1500]);
        $this->club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
    }

    private function tournamentPoint(int $before, int $after, string $name): Tournament
    {
        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'completed',
            'type' => 'americano',
            'name' => $name,
        ]);

        RatingHistory::create([
            'user_id' => $this->user->id,
            'tournament_id' => $tournament->id,
            'rating_before' => $before,
            'rating_after' => $after,
            'change' => $after - $before,
            'reason' => $name,
        ]);

        return $tournament;
    }

    public function test_отдаёт_все_точки_а_карточка_только_десять(): void
    {
        $rating = 1000;
        for ($i = 1; $i <= 14; $i++) {
            $this->tournamentPoint($rating, $rating + 10, "Турнир $i");
            $rating += 10;
        }

        $this->assertCount(14, RatingTrend::points($this->user));
        $this->assertCount(10, RatingTrend::points($this->user, RatingTrend::CARD_POINTS));

        Sanctum::actingAs($this->user);
        $response = $this->getJson('/api/mobile/profile/rating-history')->assertOk();

        $this->assertCount(14, $response->json('points'));
        $this->assertSame(14, $response->json('summary.total'));
        $this->assertSame(1010, $response->json('summary.start'));
        $this->assertSame(1140, $response->json('summary.current'));
        $this->assertSame(1140, $response->json('summary.best'));
    }

    public function test_несколько_записей_одного_турнира_это_одна_точка(): void
    {
        $tournament = $this->tournamentPoint(1000, 1010, 'Американо');

        // Второй пересчёт того же турнира — точка должна остаться одна,
        // с итоговым рейтингом.
        RatingHistory::create([
            'user_id' => $this->user->id,
            'tournament_id' => $tournament->id,
            'rating_before' => 1010,
            'rating_after' => 1025,
            'change' => 15,
            'reason' => 'Американо',
        ]);

        $points = RatingTrend::points($this->user);

        $this->assertCount(1, $points);
        $this->assertSame(1025, $points[0]['rating']);
    }

    public function test_списание_за_простой_подписано_своей_причиной(): void
    {
        $this->tournamentPoint(1000, 1100, 'Американо');
        RatingHistory::create([
            'user_id' => $this->user->id,
            'rating_before' => 1100,
            'rating_after' => 1050,
            'change' => -50,
            'reason' => RatingHistory::REASON_DECAY,
        ]);

        $points = RatingTrend::points($this->user);

        $this->assertCount(2, $points);
        $this->assertSame(RatingHistory::REASON_DECAY, $points[1]['name']);
        $this->assertTrue($points[1]['is_manual'], 'точка без турнира');
        $this->assertSame(-50, $points[1]['delta']);
    }

    public function test_у_обрезанного_списка_дельта_честная(): void
    {
        // Одиннадцать точек: в карточку попадут последние десять, и у первой
        // из них дельта должна считаться от отброшенной, а не быть пустой.
        $rating = 1000;
        for ($i = 1; $i <= 11; $i++) {
            $this->tournamentPoint($rating, $rating + 10, "Турнир $i");
            $rating += 10;
        }

        $card = RatingTrend::points($this->user, RatingTrend::CARD_POINTS);

        $this->assertSame(10, (int) $card[0]['delta']);
    }

    public function test_у_новичка_история_пустая_но_не_падает(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/mobile/profile/rating-history')->assertOk();

        $this->assertSame([], $response->json('points'));
        $this->assertSame(1500, $response->json('summary.current'));
    }
}
