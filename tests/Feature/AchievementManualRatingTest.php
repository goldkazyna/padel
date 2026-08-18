<?php

namespace Tests\Feature;

use App\Achievements\PlayerHistory;
use App\Achievements\Rules\Jump100;
use App\Achievements\Rules\LevelUp;
use App\Models\Club;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ручные правки рейтинга не должны выдавать значки.
 *
 * Администратор поднимает игрока с 2.5 до 3.0 — это больше 500 рейтинга,
 * и раньше за это прилетал и «Рывок», и «Новый уровень».
 */
class AchievementManualRatingTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(): Tournament
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'А']);

        return Tournament::factory()->create(['club_id' => $club->id]);
    }

    private function entry(User $user, int $before, int $change, ?int $tournamentId, string $reason): void
    {
        RatingHistory::create([
            'user_id' => $user->id,
            'tournament_id' => $tournamentId,
            'rating_before' => $before,
            'rating_after' => $before + $change,
            'change' => $change,
            'reason' => $reason,
        ]);
    }

    public function test_manual_bump_gives_no_jump_badge(): void
    {
        $user = User::factory()->create(['rating' => 1250]);
        $this->entry($user, 625, 625, null, RatingHistory::REASON_MANUAL);

        $this->assertSame(0, (new Jump100())->progress(PlayerHistory::for($user)));
    }

    public function test_real_tournament_jump_still_counts(): void
    {
        $user = User::factory()->create(['rating' => 1250]);
        $this->entry($user, 1130, 120, $this->tournament()->id, 'Турнир');

        $this->assertSame(1, (new Jump100())->progress(PlayerHistory::for($user)));
    }

    public function test_manual_bump_gives_no_level_badge(): void
    {
        // 620 → уровень 1.5; 1300 → уровень 1.25*4 = 1.25? считаем по формуле:
        // floor(1300/250)*0.25 = 5*0.25 = 1.25 — выше, чем floor(620/250)*0.25 = 0.5→1.0
        $user = User::factory()->create(['rating' => 1300]);
        $this->entry($user, 620, 680, null, RatingHistory::REASON_MANUAL);

        $this->assertSame(0, (new LevelUp())->progress(PlayerHistory::for($user)));
    }

    /** Ручная надбавка посреди истории не должна протаскивать наверх. */
    public function test_manual_bump_between_games_does_not_help(): void
    {
        $user = User::factory()->create(['rating' => 1300]);
        $t = $this->tournament()->id;

        $this->entry($user, 620, 10, $t, 'Турнир');          // игрой: 620 → 630
        $this->entry($user, 630, 660, null, RatingHistory::REASON_MANUAL); // руками: 630 → 1290
        $this->entry($user, 1290, 10, $t, 'Турнир');         // игрой: 1290 → 1300

        // Игрой набрано всего 20 очков — уровень не вырос.
        $this->assertSame(0, (new LevelUp())->progress(PlayerHistory::for($user)));
    }

    public function test_level_earned_by_playing_still_counts(): void
    {
        $user = User::factory()->create(['rating' => 1300]);
        $t = $this->tournament()->id;

        $this->entry($user, 990, 200, $t, 'Турнир');   // 990 (ур. 0.75→1.0) → 1190
        $this->entry($user, 1190, 120, $t, 'Турнир');  // → 1310, уровень 1.25

        $this->assertSame(1, (new LevelUp())->progress(PlayerHistory::for($user)));
    }
}
