<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Рассылка о новых значках и тихая заливка истории.
 */
class AchievementNotifyTest extends TestCase
{
    use RefreshDatabase;

    /** Игрок с рывком рейтинга, доигравший турнир пять минут назад. */
    private function playerOfRecentTournament(): User
    {
        $user = User::factory()->create(['rating' => 1300]);
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300,
            'change' => 300, 'reason' => 'Турнир',
        ]);

        $tournament = Tournament::factory()->create(['status' => 'completed']);
        $tournament->participants()->attach($user->id, ['status' => 'registered']);
        // updated_at затирается при создании — ставим отдельно.
        Tournament::where('id', $tournament->id)->update(['updated_at' => now()->subMinutes(5)]);

        return $user;
    }

    private function achievementNotifications(User $user): int
    {
        return Notification::where('user_id', $user->id)->where('type', 'achievement')->count();
    }

    public function test_command_sends_one_notification_for_the_whole_batch(): void
    {
        $user = $this->playerOfRecentTournament();

        $this->artisan('achievements:sync')->assertSuccessful();

        // Значков открылось несколько, уведомление одно: пять подряд — спам.
        $this->assertSame(1, $this->achievementNotifications($user));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'category' => 'achievement',
        ]);
    }

    public function test_second_run_stays_quiet(): void
    {
        $user = $this->playerOfRecentTournament();
        $this->artisan('achievements:sync');

        $this->artisan('achievements:sync')->assertSuccessful();

        $this->assertSame(1, $this->achievementNotifications($user));
    }

    public function test_notified_at_is_stamped(): void
    {
        $user = $this->playerOfRecentTournament();

        $this->artisan('achievements:sync');

        $this->assertSame(0, UserAchievement::where('user_id', $user->id)
            ->whereNotNull('unlocked_at')->whereNull('notified_at')->count());
    }

    public function test_players_without_recent_tournaments_are_skipped(): void
    {
        $user = User::factory()->create();
        RatingHistory::create([
            'user_id' => $user->id, 'rating_before' => 1000, 'rating_after' => 1300,
            'change' => 300, 'reason' => 'Старое',
        ]);

        $this->artisan('achievements:sync')->assertSuccessful();

        $this->assertSame(0, UserAchievement::where('user_id', $user->id)->count());
    }

    public function test_category_is_listed_for_the_app(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $keys = collect($this->getJson('/api/mobile/notifications/categories')->assertOk()
            ->json('categories'))->pluck('key');

        $this->assertContains('achievement', $keys->all());
    }

    // ===== Тихая заливка =====

    public function test_backfill_marks_history_as_already_notified(): void
    {
        $user = $this->playerOfRecentTournament();

        $this->artisan('achievements:backfill')->assertSuccessful();

        $unlocked = UserAchievement::where('user_id', $user->id)->whereNotNull('unlocked_at')->get();
        $this->assertNotEmpty($unlocked);
        foreach ($unlocked as $row) {
            $this->assertNotNull($row->notified_at, 'исторический значок помечен как уведомлённый');
        }
    }

    public function test_backfill_sends_nothing(): void
    {
        $user = $this->playerOfRecentTournament();

        $this->artisan('achievements:backfill');

        $this->assertSame(0, Notification::where('user_id', $user->id)->count());
    }

    public function test_sync_after_backfill_is_quiet_about_old_badges(): void
    {
        $user = $this->playerOfRecentTournament();

        $this->artisan('achievements:backfill');
        $this->artisan('achievements:sync');

        $this->assertSame(0, $this->achievementNotifications($user), 'за старое пушей нет');
    }

    public function test_repeat_backfill_is_safe(): void
    {
        $user = $this->playerOfRecentTournament();
        $this->artisan('achievements:backfill');
        $stamped = UserAchievement::where('user_id', $user->id)->whereNotNull('notified_at')->count();

        $this->artisan('achievements:backfill')->assertSuccessful();

        $this->assertSame($stamped, UserAchievement::where('user_id', $user->id)
            ->whereNotNull('notified_at')->count());
        $this->assertSame(0, Notification::where('user_id', $user->id)->count());
    }
}
