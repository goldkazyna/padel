<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Training;
use App\Models\TrainingParticipant;
use App\Models\User;
use App\Services\TrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    // ===== API тренера =====

    public function test_coach_creates_training(): void
    {
        $coach = $this->makeCoach();
        $club = $this->makeClub();
        Sanctum::actingAs($coach);

        $this->postJson('/api/mobile/coach/trainings', [
            'club_id' => $club->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i'),
            'duration_minutes' => 90,
            'price' => 8000,
            'capacity' => 6,
            'description' => 'Работа над подачей',
        ])->assertOk()->assertJsonPath('success', true);

        $training = Training::where('coach_id', $coach->id)->firstOrFail();
        $this->assertSame($club->id, $training->club_id);
        $this->assertSame(90, $training->duration_minutes);
        $this->assertSame(6, $training->capacity);
        $this->assertSame('planned', $training->status);
    }

    public function test_player_cannot_create_training(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'player']));

        $this->postJson('/api/mobile/coach/trainings', [
            'club_id' => $this->makeClub()->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i'),
            'duration_minutes' => 60,
            'price' => 0,
            'capacity' => 4,
        ])->assertStatus(403);

        $this->assertSame(0, Training::count());
    }

    public function test_coach_sees_participants_with_phones(): void
    {
        $coach = $this->makeCoach();
        $training = $this->makeTraining(['coach_id' => $coach->id]);
        $player = User::factory()->create(['name' => 'Игрок', 'phone' => '77771234567']);
        $this->service()->join($training, $player);

        Sanctum::actingAs($coach);
        $res = $this->getJson("/api/mobile/coach/trainings/{$training->id}")->assertOk();

        $this->assertSame('Игрок', $res->json('training.participants.0.name'));
        $this->assertSame(
            '77771234567',
            $res->json('training.participants.0.phone'),
            'телефон нужен для звонка и WhatsApp'
        );
    }

    public function test_coach_cannot_open_foreign_training(): void
    {
        $training = $this->makeTraining();
        Sanctum::actingAs($this->makeCoach());

        $this->getJson("/api/mobile/coach/trainings/{$training->id}")->assertStatus(403);
    }

    public function test_coach_removes_participant_through_api(): void
    {
        $coach = $this->makeCoach();
        $training = $this->makeTraining(['coach_id' => $coach->id]);
        $player = User::factory()->create();
        $this->service()->join($training, $player);

        Sanctum::actingAs($coach);
        $this->deleteJson("/api/mobile/coach/trainings/{$training->id}/participants/{$player->id}")
            ->assertOk();

        $this->assertSame(0, $training->participants()->count());
    }

    public function test_coach_cancels_and_completes_through_api(): void
    {
        $coach = $this->makeCoach();
        Sanctum::actingAs($coach);

        // Отмена доступна сразу.
        $upcoming = $this->makeTraining(['coach_id' => $coach->id]);
        $this->postJson("/api/mobile/coach/trainings/{$upcoming->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $upcoming->fresh()->status);

        // Завершение — только после окончания.
        $running = $this->makeTraining([
            'coach_id' => $coach->id,
            'starts_at' => now()->subMinutes(10),
        ]);
        $this->postJson("/api/mobile/coach/trainings/{$running->id}/complete")->assertStatus(422);

        $finished = $this->makeTraining([
            'coach_id' => $coach->id,
            'starts_at' => now()->subHours(3),
        ]);
        $this->postJson("/api/mobile/coach/trainings/{$finished->id}/complete")->assertOk();
        $this->assertSame('completed', $finished->fresh()->status);
    }

    public function test_coach_club_list_hides_communities(): void
    {
        $club = $this->makeClub();
        $community = Club::create([
            'name' => 'Комьюнити',
            'address' => 'А',
            'city' => 'Алматы',
            'is_community' => true,
        ]);

        Sanctum::actingAs($this->makeCoach());
        $ids = array_column($this->getJson('/api/mobile/coach/clubs')->assertOk()->json('clubs'), 'id');

        $this->assertContains($club->id, $ids);
        $this->assertNotContains($community->id, $ids, 'комьюнити не показываем');
    }

    // ===== API игрока =====

    public function test_player_sees_upcoming_trainings_sorted_by_date(): void
    {
        $late = $this->makeTraining(['starts_at' => now()->addDays(3)]);
        $soon = $this->makeTraining(['starts_at' => now()->addHours(5)]);
        // Прошедшие и отменённые в списке не нужны.
        $this->makeTraining(['starts_at' => now()->subDay()]);
        $this->makeTraining(['status' => 'cancelled']);

        Sanctum::actingAs(User::factory()->create());
        $list = $this->getJson('/api/mobile/trainings')->assertOk()->json('trainings');

        $this->assertCount(2, $list);
        $this->assertSame($soon->id, $list[0]['id'], 'ближайшая сверху');
        $this->assertSame($late->id, $list[1]['id']);
        $this->assertSame(4, $list[0]['free_slots']);
        $this->assertFalse($list[0]['is_joined']);
    }

    public function test_player_joins_and_leaves_through_api(): void
    {
        $training = $this->makeTraining();
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $this->postJson("/api/mobile/trainings/{$training->id}/join")->assertOk();
        $this->assertSame(1, $training->participants()->count());

        $list = $this->getJson('/api/mobile/trainings')->assertOk()->json('trainings');
        $this->assertTrue($list[0]['is_joined']);
        $this->assertSame(3, $list[0]['free_slots']);

        $this->postJson("/api/mobile/trainings/{$training->id}/leave")->assertOk();
        $this->assertSame(0, $training->participants()->count());
    }

    public function test_join_returns_error_when_no_slots(): void
    {
        $training = $this->makeTraining(['capacity' => 1]);
        $this->service()->join($training, User::factory()->create());

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/mobile/trainings/{$training->id}/join")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_counts_feed_badges(): void
    {
        $player = User::factory()->create();
        $mine = $this->makeTraining();
        $this->service()->join($mine, $player);
        $this->makeTraining(['starts_at' => now()->addDays(2)]);
        // Забитая под завязку в «доступные» не идёт.
        $full = $this->makeTraining(['capacity' => 1]);
        $this->service()->join($full, User::factory()->create());

        Sanctum::actingAs($player);
        $res = $this->getJson('/api/mobile/trainings/count')->assertOk();

        $this->assertSame(1, $res->json('upcoming'), 'на скольких занятиях я записан');
        $this->assertSame(2, $res->json('available'), 'куда ещё можно записаться');
    }

    public function test_my_trainings_split_by_time(): void
    {
        $player = User::factory()->create();
        $upcoming = $this->makeTraining();
        $past = $this->makeTraining(['starts_at' => now()->subDays(2)]);
        foreach ([$upcoming, $past] as $t) {
            TrainingParticipant::create(['training_id' => $t->id, 'user_id' => $player->id]);
        }

        Sanctum::actingAs($player);
        $res = $this->getJson('/api/mobile/trainings/my')->assertOk();

        $this->assertSame($upcoming->id, $res->json('upcoming.0.id'));
        $this->assertSame($past->id, $res->json('past.0.id'));
    }

    public function test_coach_club_list_supports_search(): void
    {
        Club::create(['name' => 'Padel Arena', 'address' => 'А', 'city' => 'Алматы']);
        Club::create(['name' => 'Astana Padel', 'address' => 'Б', 'city' => 'Астана']);
        Club::create(['name' => 'Теннис Центр', 'address' => 'В', 'city' => 'Алматы']);

        Sanctum::actingAs($this->makeCoach());

        // По названию.
        $names = array_column(
            $this->getJson('/api/mobile/coach/clubs?search=arena')->assertOk()->json('clubs'),
            'name'
        );
        $this->assertSame(['Padel Arena'], $names);

        // По городу — тренер ищет и так тоже.
        $names = array_column(
            $this->getJson('/api/mobile/coach/clubs?search=Астана')->assertOk()->json('clubs'),
            'name'
        );
        $this->assertSame(['Astana Padel'], $names);

        // Пустой запрос отдаёт всё.
        $all = $this->getJson('/api/mobile/coach/clubs?search=')->assertOk()->json('clubs');
        $this->assertCount(3, $all);
    }
}
