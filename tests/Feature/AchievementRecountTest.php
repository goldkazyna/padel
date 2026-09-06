<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пересчёт значка после смены правила.
 *
 * Обычный sync прогресс не откатывает — и после того, как из «Знатока
 * форматов» убрали Round Robin, у людей осталось бы старое число: «7 из 7»
 * при семи форматах, один из которых не сыгран.
 */
class AchievementRecountTest extends TestCase
{
    use RefreshDatabase;

    public function test_прогресс_опускается_до_настоящего(): void
    {
        $user = User::factory()->create();
        // Игрок не сыграл ни одного матча — настоящий прогресс нулевой.
        UserAchievement::create([
            'user_id' => $user->id,
            'code' => 'formats_all',
            'progress' => 7,
            'target' => 8,
            'unlocked_at' => null,
        ]);

        $this->artisan('achievements:recount', ['code' => 'formats_all'])
            ->assertSuccessful();

        $row = UserAchievement::where('user_id', $user->id)->first();
        $this->assertSame(0, (int) $row->progress);
        $this->assertSame(7, (int) $row->target, 'цель подтянулась к новому правилу');
        $this->assertNull($row->unlocked_at);
    }

    public function test_выданный_значок_не_отбираем(): void
    {
        $user = User::factory()->create();
        $unlockedAt = now()->subMonth();
        UserAchievement::create([
            'user_id' => $user->id,
            'code' => 'formats_all',
            'progress' => 8,
            'target' => 8,
            'unlocked_at' => $unlockedAt,
        ]);

        $this->artisan('achievements:recount', ['code' => 'formats_all'])
            ->assertSuccessful();

        $row = UserAchievement::where('user_id', $user->id)->first();
        $this->assertNotNull($row->unlocked_at, 'значок остался у игрока');
        // Под открытым значком не должно быть «0 из 7».
        $this->assertSame(7, (int) $row->progress);
        $this->assertSame(7, (int) $row->target);
    }

    public function test_неизвестный_код_это_ошибка(): void
    {
        $this->artisan('achievements:recount', ['code' => 'нет_такого'])
            ->assertFailed();
    }
}
