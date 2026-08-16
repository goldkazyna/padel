<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Хранилище прогресса по значкам.
 */
class AchievementStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_is_stored_per_player_and_code(): void
    {
        $user = User::factory()->create();

        $row = UserAchievement::create([
            'user_id' => $user->id,
            'code' => 'streak_5',
            'progress' => 3,
            'target' => 5,
        ]);

        $this->assertNull($row->unlocked_at, 'значок ещё не получен');
        $this->assertNull($row->notified_at, 'пуш ещё не отправлен');
    }

    public function test_same_code_cannot_repeat_for_one_player(): void
    {
        $user = User::factory()->create();
        UserAchievement::create([
            'user_id' => $user->id, 'code' => 'debut', 'progress' => 1, 'target' => 1,
        ]);

        $this->expectException(QueryException::class);
        UserAchievement::create([
            'user_id' => $user->id, 'code' => 'debut', 'progress' => 1, 'target' => 1,
        ]);
    }

    public function test_unlocked_and_notified_are_dates(): void
    {
        $user = User::factory()->create();
        $row = UserAchievement::create([
            'user_id' => $user->id, 'code' => 'first_win', 'progress' => 1, 'target' => 1,
            'unlocked_at' => now(), 'notified_at' => now(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $row->fresh()->unlocked_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $row->fresh()->notified_at);
    }
}
