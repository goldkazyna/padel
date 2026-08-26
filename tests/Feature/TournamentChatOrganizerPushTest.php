<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\DeviceToken;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Участник написал в чат — пуш уходит организаторам, и только им.
 * Остальным игрокам чужая переписка в кармане не нужна.
 */
class TournamentChatOrganizerPushTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{tokens: array, title: string, body: string}> */
    private array $pushes = [];

    private Club $club;
    private Tournament $tournament;
    private User $organizer;
    private User $player;
    private User $otherPlayer;

    protected function setUp(): void
    {
        parent::setUp();

        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendMulticastToTokens')
            ->andReturnUsing(function ($tokens, $title, $body) {
                $this->pushes[] = ['tokens' => $tokens, 'title' => $title, 'body' => $body];

                return true;
            });
        $this->instance(FCMNotificationService::class, $mock);

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->tournament = Tournament::create([
            'club_id' => $this->club->id, 'name' => 'Вечерний турнир', 'type' => 'americano',
            'status' => 'open', 'start_date' => '2026-08-20 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);

        $this->organizer = $this->makeUser('club_admin', 'token-organizer', 'Анна Менеджер');
        $this->organizer->adminClubs()->attach($this->club->id);

        $this->player = $this->makeUser('player', 'token-player', 'Ерлан Игрок');
        $this->otherPlayer = $this->makeUser('player', 'token-other', 'Второй Игрок');

        $this->tournament->participants()->attach($this->player->id, ['status' => 'registered']);
        $this->tournament->participants()->attach($this->otherPlayer->id, ['status' => 'registered']);
    }

    private function makeUser(string $role, string $token, string $name): User
    {
        $user = User::factory()->create(['role' => $role, 'name' => $name, 'level' => 3.0]);
        DeviceToken::create(['user_id' => $user->id, 'token' => $token, 'platform' => 'android']);

        return $user;
    }

    private function send(User $author, string $text)
    {
        return $this->actingAs($author, 'sanctum')->postJson(
            "/api/mobile/tournaments/{$this->tournament->id}/chat/messages",
            ['text' => $text]
        );
    }

    public function test_сообщение_участника_уходит_организатору(): void
    {
        $this->send($this->player, 'Оплатил, скиньте номер корта')->assertOk();

        $this->assertCount(1, $this->pushes, 'ровно одна рассылка');
        $this->assertSame(['token-organizer'], $this->pushes[0]['tokens'], 'только организатору');
        $this->assertSame('Вечерний турнир', $this->pushes[0]['title']);
        $this->assertStringContainsString('Ерлан Игрок', $this->pushes[0]['body'], 'видно, кто написал');
        $this->assertStringContainsString('Оплатил', $this->pushes[0]['body']);
    }

    public function test_другим_участникам_ничего_не_приходит(): void
    {
        $this->send($this->player, 'Кто-нибудь едет с района?')->assertOk();

        $tokens = collect($this->pushes)->flatMap(fn ($p) => $p['tokens'])->all();
        $this->assertNotContains('token-other', $tokens, 'соседу по турниру это не нужно');
        $this->assertNotContains('token-player', $tokens, 'и самому автору тоже');
    }

    public function test_сообщение_организатора_по_прежнему_уходит_участникам(): void
    {
        $this->send($this->organizer, 'Начинаем в 19:00')->assertOk();

        $tokens = collect($this->pushes)->flatMap(fn ($p) => $p['tokens'])->all();
        $this->assertContains('token-player', $tokens);
        $this->assertContains('token-other', $tokens);
        $this->assertNotContains('token-organizer', $tokens, 'себе организатор не пишет');
    }

    public function test_организатор_может_отключить_уведомления(): void
    {
        $this->organizer->update(['notify_organizer_chat' => false]);

        $this->send($this->player, 'Здравствуйте')->assertOk();

        $this->assertCount(0, $this->pushes, 'выключил — не беспокоим');
    }

    public function test_модератор_клуба_тоже_получает(): void
    {
        $moderator = $this->makeUser('club_moderator', 'token-moderator', 'Дежурный');
        $moderator->moderatorClubs()->attach($this->club->id);

        $this->send($this->player, 'Можно перенести?')->assertOk();

        $tokens = collect($this->pushes)->flatMap(fn ($p) => $p['tokens'])->all();
        $this->assertContains('token-organizer', $tokens);
        $this->assertContains('token-moderator', $tokens);
    }
}
