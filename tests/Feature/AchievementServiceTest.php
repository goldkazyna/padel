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
        // Запись за турнир обязана нести tournament_id: значок «Рывок» считает
        // только турниры, иначе его выдавала бы ручная правка рейтинга.
        $club = \App\Models\Club::create(['name' => 'Клуб', 'address' => 'А']);
        $tournament = \App\Models\Tournament::factory()->create(['club_id' => $club->id]);

        RatingHistory::create([
            'user_id' => $user->id, 'tournament_id' => $tournament->id,
            'rating_before' => 1000, 'rating_after' => 1300,
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
        $this->assertSame(18, UserAchievement::where('user_id', $user->id)->count(),
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

        $this->assertCount(18, $owner, 'владелец видит и незакрытые значки');
        $this->assertNotEmpty($visitor);
        foreach ($visitor as $item) {
            $this->assertNotNull($item['unlocked_at'], 'гостю показываем только полученное');
        }
        $this->assertArrayHasKey('progress', $owner[0]);
        $this->assertArrayHasKey('title', $owner[0]);
        $this->assertArrayHasKey('group', $owner[0]);
    }

    public function test_medal_tier_is_exposed(): void
    {
        $user = $this->playerWithJump();
        $service = app(AchievementService::class);
        $service->sync($user);

        $byCode = collect($service->forOwner($user))->keyBy('code');

        $this->assertSame('bronze', $byCode['first_win']['tier']);
        $this->assertSame('silver', $byCode['streak_5']['tier']);
        $this->assertSame('gold', $byCode['veteran_50']['tier']);
    }

    /** Доли считаем от тех, кто играл, а не от всех зарегистрированных. */
    public function test_rarity_counts_share_of_players_who_played(): void
    {
        $service = app(AchievementService::class);

        // 25 игроков сыграли турнир, из них 5 сделали рывок рейтинга.
        for ($i = 0; $i < 25; $i++) {
            $user = User::factory()->create();
            UserAchievement::create([
                'user_id' => $user->id, 'code' => 'debut',
                'progress' => 1, 'target' => 1, 'unlocked_at' => now(),
            ]);
            if ($i < 5) {
                UserAchievement::create([
                    'user_id' => $user->id, 'code' => 'jump_100',
                    'progress' => 1, 'target' => 1, 'unlocked_at' => now(),
                ]);
            }
        }
        // Ещё 100 зарегистрированных, но не игравших: на доли влиять не должны.
        User::factory()->count(100)->create();

        $byCode = collect($service->forOwner($user))->keyBy('code');

        $this->assertSame(100, $byCode['debut']['rarity']);
        $this->assertSame(20, $byCode['jump_100']['rarity'], '5 из 25 играющих');
    }

    /** На маленькой базе доля — шум, показывать её нечестно. */
    public function test_rarity_is_hidden_while_there_are_few_players(): void
    {
        $user = $this->playerWithJump();
        $service = app(AchievementService::class);
        $service->sync($user);

        $byCode = collect($service->forOwner($user))->keyBy('code');

        $this->assertNull($byCode['jump_100']['rarity']);
    }

    public function test_visitor_view_does_not_recalculate(): void
    {
        $user = $this->playerWithJump();

        // Пересчёта не было — гость видит пусто, а не свежий расчёт.
        $this->assertSame([], app(AchievementService::class)->forVisitor($user));
        $this->assertSame(0, UserAchievement::where('user_id', $user->id)->count());
    }
}
