<?php

namespace Tests\Feature;

use App\Models\RatingHistory;
use App\Models\User;
use App\Models\UserAchievement;
use App\Services\AchievementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пересчёт значков и их выдача своему и чужому.
 */
class AchievementServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Игрок с рывком рейтинга: сразу закрывает «Рывок» и «Новый уровень». */
    private function playerWithJump(): User
    {
        $user = User::factory()->create(['rating' => 1300]);
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300,
            'change' => 300, 'reason' => 'Турнир',
        ]);

        return $user;
    }

    public function test_sync_unlocks_and_reports_new_codes(): void
    {
        $user = $this->playerWithJump();

        $fresh = app(AchievementService::class)->sync($user);

        $this->assertContains('jump_100', $fresh);
        $this->assertContains('level_up', $fresh);
        $this->assertNotNull(
            UserAchievement::where('user_id', $user->id)->where('code', 'jump_100')->value('unlocked_at')
        );
    }

    public function test_repeat_sync_reports_nothing_new(): void
    {
        $user = $this->playerWithJump();
        $service = app(AchievementService::class);
        $service->sync($user);

        $this->assertSame([], $service->sync($user), 'второй проход не выдаёт те же значки заново');
        $this->assertSame(15, UserAchievement::where('user_id', $user->id)->count(),
            'по строке на каждый значок, дублей нет');
    }

    public function test_unlocked_at_does_not_move_on_repeat(): void
    {
        $user = $this->playerWithJump();
        $service = app(AchievementService::class);
        $service->sync($user);

        $first = UserAchievement::where('user_id', $user->id)->where('code', 'jump_100')->value('unlocked_at');
        $this->travel(1)->days();
        $service->sync($user);

        $this->assertEquals(
            $first,
            UserAchievement::where('user_id', $user->id)->where('code', 'jump_100')->value('unlocked_at'),
            'дата получения не переписывается'
        );
    }

    /** Прогресс не откатывается: правка счёта задним числом не отбирает награду. */
    public function test_progress_never_goes_backwards(): void
    {
        $user = User::factory()->create();
        UserAchievement::create([
            'user_id' => $user->id, 'code' => 'regular_5', 'progress' => 4, 'target' => 5,
        ]);

        app(AchievementService::class)->sync($user);

        $this->assertSame(4, UserAchievement::where('user_id', $user->id)
            ->where('code', 'regular_5')->value('progress'));
    }

    public function test_owner_sees_progress_visitor_sees_only_unlocked(): void
    {
        $user = $this->playerWithJump();
        $service = app(AchievementService::class);
        $service->sync($user);

        $owner = $service->forOwner($user);
        $visitor = $service->forVisitor($user);

        $this->assertCount(15, $owner, 'владелец видит и незакрытые значки');
        $this->assertNotEmpty($visitor);
        foreach ($visitor as $item) {
            $this->assertNotNull($item['unlocked_at'], 'гостю показываем только полученное');
        }
        $this->assertArrayHasKey('progress', $owner[0]);
        $this->assertArrayHasKey('title', $owner[0]);
        $this->assertArrayHasKey('group', $owner[0]);
    }

    public function test_visitor_view_does_not_recalculate(): void
    {
        $user = $this->playerWithJump();

        // Пересчёта не было — гость видит пусто, а не свежий расчёт.
        $this->assertSame([], app(AchievementService::class)->forVisitor($user));
        $this->assertSame(0, UserAchievement::where('user_id', $user->id)->count());
    }
}
