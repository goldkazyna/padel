<?php

namespace Tests\Feature;

use App\Models\RatingHistory;
use App\Models\User;
use App\Models\UserAchievement;
use App\Services\AchievementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Эндпоинты значков: свои с прогрессом, чужие только полученные.
 */
class AchievementApiTest extends TestCase
{
    use RefreshDatabase;

    private function playerWithJump(): User
    {
        $user = User::factory()->create(['rating' => 1300]);
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300,
            'change' => 300, 'reason' => 'Турнир',
        ]);

        return $user;
    }

    public function test_own_achievements_are_recalculated_on_open(): void
    {
        $user = $this->playerWithJump();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/achievements')->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertCount(15, $response->json('achievements'));
        $this->assertSame(15, UserAchievement::where('user_id', $user->id)->count(),
            'открытие экрана пересчитывает значки');

        $jump = collect($response->json('achievements'))->firstWhere('code', 'jump_100');
        $this->assertNotNull($jump['unlocked_at']);
        $this->assertSame('Рывок', $jump['title']);
        $this->assertSame('rating', $jump['group']);
    }

    public function test_open_does_not_send_push(): void
    {
        $user = $this->playerWithJump();
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/achievements')->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id, 'type' => 'achievement',
        ]);
        $this->assertNull(
            UserAchievement::where('user_id', $user->id)->where('code', 'jump_100')->value('notified_at'),
            'экран не помечает значок отправленным — иначе крон промолчит'
        );
    }

    public function test_other_player_shows_only_unlocked(): void
    {
        $other = $this->playerWithJump();
        app(AchievementService::class)->sync($other);
        Sanctum::actingAs(User::factory()->create());

        $items = $this->getJson("/api/mobile/achievements/player/{$other->id}")
            ->assertOk()
            ->json('achievements');

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertNotNull($item['unlocked_at']);
        }
    }

    public function test_other_player_view_does_not_recalculate(): void
    {
        $other = $this->playerWithJump();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/achievements/player/{$other->id}")->assertOk();

        $this->assertSame(0, UserAchievement::where('user_id', $other->id)->count());
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/mobile/achievements')->assertUnauthorized();
    }
}
