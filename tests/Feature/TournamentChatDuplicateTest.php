<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\DeviceToken;
use App\Models\Tournament;
use App\Models\TournamentChatMessage;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Mockery;
use Tests\TestCase;

/**
 * Двойной тап по кнопке отправки создавал два одинаковых сообщения в чате
 * и два пуша участникам. Повтор того же текста в первые секунды считается
 * тем же сообщением.
 */
class TournamentChatDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private int $pushCalls = 0;
    private Tournament $tournament;
    private User $organizer;
    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendMulticastToTokens')->andReturnUsing(function () {
            $this->pushCalls++;

            return true;
        });
        $this->instance(FCMNotificationService::class, $mock);

        $club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->tournament = Tournament::create([
            'club_id' => $club->id, 'name' => 'Вечерний турнир', 'type' => 'americano',
            'status' => 'open', 'start_date' => '2026-08-20 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);

        $this->organizer = User::factory()->create(['role' => 'club_admin']);
        $this->organizer->adminClubs()->attach($club->id);

        $this->player = User::factory()->create(['role' => 'player', 'level' => 3.0]);
        DeviceToken::create([
            'user_id' => $this->player->id, 'token' => 'token-player', 'platform' => 'android',
        ]);
        $this->tournament->participants()->attach($this->player->id, ['status' => 'registered']);
    }

    private function send(User $author, string $text)
    {
        return $this->actingAs($author, 'sanctum')->postJson(
            "/api/mobile/tournaments/{$this->tournament->id}/chat/messages",
            ['text' => $text]
        );
    }

    public function test_повтор_того_же_текста_не_создаёт_второе_сообщение(): void
    {
        $first = $this->send($this->organizer, 'Начинаем в 19:00, не опаздывайте')->assertOk();
        $second = $this->send($this->organizer, 'Начинаем в 19:00, не опаздывайте')->assertOk();

        $this->assertSame(1, TournamentChatMessage::count(), 'в чате должно быть одно сообщение');
        $this->assertSame(
            $first->json('message.id'),
            $second->json('message.id'),
            'повтор возвращает уже созданное сообщение'
        );
        $this->assertSame(1, $this->pushCalls, 'и пуш уходит один раз');
    }

    public function test_разные_сообщения_проходят_как_обычно(): void
    {
        $this->send($this->organizer, 'Начинаем в 19:00')->assertOk();
        $this->send($this->organizer, 'Корт номер три')->assertOk();

        $this->assertSame(2, TournamentChatMessage::count());
        $this->assertSame(2, $this->pushCalls);
    }

    public function test_тот_же_текст_позже_отправляется_снова(): void
    {
        // «Всем привет» через полчаса — нормальное сообщение, а не дубль.
        $this->send($this->organizer, 'Кто ещё едет?')->assertOk();

        Date::setTestNow(now()->addMinutes(30));
        $this->send($this->organizer, 'Кто ещё едет?')->assertOk();
        Date::setTestNow();

        $this->assertSame(2, TournamentChatMessage::count());
    }

    public function test_одинаковый_текст_от_разных_людей_не_склеивается(): void
    {
        $this->send($this->organizer, '+1')->assertOk();
        $this->send($this->player, '+1')->assertOk();

        $this->assertSame(2, TournamentChatMessage::count(), 'у каждого своё сообщение');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }
}
