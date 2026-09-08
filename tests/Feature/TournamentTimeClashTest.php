<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Играть в двух турнирах на одно время нельзя.
 *
 * Человек записывался на два турнира на один вечер и узнавал об этом уже на
 * корте: организатор ждал игрока, которого в это время ждали в другом клубе.
 */
class TournamentTimeClashTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Пуши в тестах не шлём: без ключей Firebase запись падала бы на них.
        $fcm = Mockery::mock(FCMNotificationService::class);
        $fcm->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $fcm);

        $this->club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        $this->user = User::factory()->create(['level' => 3.0, 'rating' => 2000]);
    }

    private function tournament(string $start, ?int $hours = 2, string $name = 'Турнир'): Tournament
    {
        return Tournament::factory()->create([
            'club_id' => $this->club->id,
            'name' => $name,
            'type' => 'americano',
            'status' => 'open',
            'start_date' => $start,
            'duration_hours' => $hours,
            'max_participants' => 8,
            'min_level' => 1,
            'max_level' => 7,
        ]);
    }

    private function register(Tournament $tournament)
    {
        Sanctum::actingAs($this->user);

        return $this->postJson("/api/mobile/tournaments/{$tournament->id}/register");
    }

    public function test_на_пересекающийся_турнир_не_пускает(): void
    {
        $busy = $this->tournament('2026-09-20 19:00:00', 2, 'Вечерний американо');
        $busy->participants()->attach($this->user->id, ['status' => 'registered']);

        $other = $this->tournament('2026-09-20 20:00:00', 2, 'Поздний микс');

        $response = $this->register($other);

        $response->assertStatus(400);
        $this->assertStringContainsString('Вечерний американо', $response->json('message'));
        $this->assertSame(0, $other->participants()->count());
    }

    public function test_встык_записаться_можно(): void
    {
        // 17:00–19:00 и 19:00 — это не пересечение: успевает и туда, и туда.
        $busy = $this->tournament('2026-09-20 17:00:00', 2);
        $busy->participants()->attach($this->user->id, ['status' => 'registered']);

        $this->register($this->tournament('2026-09-20 19:00:00', 2))->assertOk();
    }

    public function test_без_длительности_считаем_два_часа(): void
    {
        $busy = $this->tournament('2026-09-20 19:00:00', null);
        $busy->participants()->attach($this->user->id, ['status' => 'registered']);

        // 20:30 попадает в окно 19:00–21:00.
        $this->register($this->tournament('2026-09-20 20:30:00', null))->assertStatus(400);
        // А 21:00 — уже нет.
        $this->register($this->tournament('2026-09-20 21:00:00', null))->assertOk();
    }

    public function test_лист_ожидания_не_занимает_время(): void
    {
        // В очереди человек не играет: запрещать ему другой турнир не за что.
        $busy = $this->tournament('2026-09-20 19:00:00');
        $busy->participants()->attach($this->user->id, ['status' => 'waiting']);

        $this->register($this->tournament('2026-09-20 19:30:00'))->assertOk();
    }

    public function test_отменённый_турнир_время_не_держит(): void
    {
        $busy = $this->tournament('2026-09-20 19:00:00');
        $busy->participants()->attach($this->user->id, ['status' => 'registered']);
        $busy->update(['status' => 'cancelled']);

        $this->register($this->tournament('2026-09-20 19:30:00'))->assertOk();
    }

    public function test_карточка_турнира_предупреждает_заранее(): void
    {
        $busy = $this->tournament('2026-09-20 19:00:00', 2, 'Вечерний американо');
        $busy->participants()->attach($this->user->id, ['status' => 'registered']);

        $other = $this->tournament('2026-09-20 20:00:00');
        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/mobile/tournaments/{$other->id}");

        $response->assertOk()->assertJsonPath('tournament.can_register', false);
        $this->assertStringContainsString(
            'В это время вы уже играете',
            $response->json('tournament.block_reason')
        );
    }
}
