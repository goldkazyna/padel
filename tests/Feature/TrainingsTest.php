<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Training;
use App\Models\TrainingParticipant;
use App\Models\User;
use App\Services\TrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Тренировки: тренер создаёт и ведёт занятие, игроки записываются.
 */
class TrainingsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TrainingService
    {
        return app(TrainingService::class);
    }

    private function makeClub(): Club
    {
        return Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);
    }

    private function makeCoach(): User
    {
        return User::factory()->create(['role' => 'coach']);
    }

    private function makeTraining(array $attrs = []): Training
    {
        return Training::create(array_merge([
            'coach_id' => $this->makeCoach()->id,
            'club_id' => $this->makeClub()->id,
            'starts_at' => now()->addDay(),
            'duration_minutes' => 60,
            'price' => 5000,
            'capacity' => 4,
            'status' => 'planned',
        ], $attrs));
    }

    // ===== Запись игрока =====

    public function test_player_joins_training(): void
    {
        $training = $this->makeTraining();
        $player = User::factory()->create();

        $this->service()->join($training, $player);

        $this->assertSame(1, $training->participants()->count());
        $this->assertTrue($training->players()->where('users.id', $player->id)->exists());
    }

    public function test_join_is_idempotent(): void
    {
        $training = $this->makeTraining();
        $player = User::factory()->create();

        $this->service()->join($training, $player);
        $this->service()->join($training, $player);

        $this->assertSame(1, $training->participants()->count(), 'повторная запись не создаёт вторую строку');
    }

    public function test_join_blocked_when_no_free_slots(): void
    {
        $training = $this->makeTraining(['capacity' => 2]);
        foreach (range(1, 2) as $i) {
            $this->service()->join($training->fresh(), User::factory()->create());
        }

        $this->expectException(RuntimeException::class);
        $this->service()->join($training->fresh(), User::factory()->create());
    }

    public function test_join_blocked_for_past_training(): void
    {
        $training = $this->makeTraining(['starts_at' => now()->subHour()]);

        $this->expectException(RuntimeException::class);
        $this->service()->join($training, User::factory()->create());
    }

    public function test_join_blocked_for_cancelled_training(): void
    {
        $training = $this->makeTraining(['status' => 'cancelled']);

        $this->expectException(RuntimeException::class);
        $this->service()->join($training, User::factory()->create());
    }

    public function test_player_leaves_training(): void
    {
        $training = $this->makeTraining();
        $player = User::factory()->create();
        $this->service()->join($training, $player);

        $this->service()->leave($training->fresh(), $player);

        $this->assertSame(0, $training->participants()->count());
    }

    // ===== Управление тренером =====

    public function test_coach_removes_participant(): void
    {
        $training = $this->makeTraining();
        $player = User::factory()->create();
        $this->service()->join($training, $player);

        $this->service()->removeParticipant($training->fresh(), $player);

        $this->assertSame(0, $training->participants()->count());
    }

    public function test_complete_blocked_until_training_ends(): void
    {
        // Занятие идёт прямо сейчас: началось 10 минут назад, длится час.
        $training = $this->makeTraining(['starts_at' => now()->subMinutes(10)]);

        $this->expectException(RuntimeException::class);
        $this->service()->complete($training);
    }

    public function test_complete_after_training_ended(): void
    {
        $training = $this->makeTraining(['starts_at' => now()->subHours(2)]);

        $this->service()->complete($training);

        $this->assertSame('completed', $training->fresh()->status);
    }

    public function test_cancel_works_at_any_moment(): void
    {
        $training = $this->makeTraining();

        $this->service()->cancel($training);

        $this->assertSame('cancelled', $training->fresh()->status);
    }

    public function test_cancel_notifies_participants(): void
    {
        $training = $this->makeTraining();
        $player = User::factory()->create();
        $this->service()->join($training, $player);

        $this->service()->cancel($training->fresh());

        $this->assertDatabaseHas('notifications', [
            'user_id' => $player->id,
            'type' => 'training_cancelled',
        ]);
    }

    public function test_completed_training_cannot_be_cancelled(): void
    {
        $training = $this->makeTraining(['starts_at' => now()->subHours(2)]);
        $this->service()->complete($training);

        $this->expectException(RuntimeException::class);
        $this->service()->cancel($training->fresh());
    }
}
