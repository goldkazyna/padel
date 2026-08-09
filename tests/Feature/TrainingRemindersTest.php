<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Notification;
use App\Models\Training;
use App\Models\TrainingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Напоминания о тренировке: за сутки, за два часа и за час.
 *
 * Время тренировки хранится как настенное время Алматы — так же, как у
 * турниров, поэтому и тесты считают отсечки в этой зоне.
 */
class TrainingRemindersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Training,1:User} */
    private function makeTrainingIn(string $interval): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);
        $coach = User::factory()->create(['role' => 'coach']);

        $startsAt = now()->timezone('Asia/Almaty')->add($interval);

        $training = Training::create([
            'coach_id' => $coach->id,
            'club_id' => $club->id,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
            'price' => 5000,
            'capacity' => 4,
            'status' => 'planned',
        ]);

        $player = User::factory()->create(['notify_tournament_reminders' => true]);
        TrainingParticipant::create([
            'training_id' => $training->id,
            'user_id' => $player->id,
        ]);

        return [$training, $player];
    }

    private function reminders(User $player): \Illuminate\Support\Collection
    {
        return Notification::where('user_id', $player->id)
            ->where('type', 'training_reminder')
            ->get();
    }

    public function test_sends_daily_reminder(): void
    {
        [$training, $player] = $this->makeTrainingIn('20 hours');

        $this->artisan('trainings:send-reminders')->assertExitCode(0);

        $this->assertCount(1, $this->reminders($player));
        $this->assertNotNull($training->participants()->first()->reminded_1d_at);
    }

    public function test_does_not_repeat_on_second_run(): void
    {
        [$training, $player] = $this->makeTrainingIn('20 hours');

        $this->artisan('trainings:send-reminders')->assertExitCode(0);
        $this->artisan('trainings:send-reminders')->assertExitCode(0);

        $this->assertCount(1, $this->reminders($player), 'повторный прогон ничего не шлёт');
    }

    public function test_sends_all_three_when_start_is_close(): void
    {
        // За 40 минут до начала актуальны все три отсечки сразу.
        [$training, $player] = $this->makeTrainingIn('40 minutes');

        $this->artisan('trainings:send-reminders')->assertExitCode(0);

        $this->assertCount(3, $this->reminders($player));
        $participant = $training->participants()->first();
        $this->assertNotNull($participant->reminded_1d_at);
        $this->assertNotNull($participant->reminded_2h_at);
        $this->assertNotNull($participant->reminded_1h_at);
    }

    public function test_ignores_far_and_cancelled_trainings(): void
    {
        // До занятия больше суток — рано.
        [$far, $farPlayer] = $this->makeTrainingIn('3 days');
        // Отменённое занятие напоминаний не шлёт.
        [$cancelled, $cancelledPlayer] = $this->makeTrainingIn('5 hours');
        $cancelled->update(['status' => 'cancelled']);

        $this->artisan('trainings:send-reminders')->assertExitCode(0);

        $this->assertCount(0, $this->reminders($farPlayer));
        $this->assertCount(0, $this->reminders($cancelledPlayer));
    }

    public function test_respects_user_notification_setting(): void
    {
        [$training, $player] = $this->makeTrainingIn('20 hours');
        $player->update(['notify_tournament_reminders' => false]);

        $this->artisan('trainings:send-reminders')->assertExitCode(0);

        $this->assertCount(0, $this->reminders($player));
    }
}
