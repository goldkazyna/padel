<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FCMNotificationService;
use App\Services\TournamentPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Текст push-уведомления о турнире правится перед отправкой: организатор
 * видит заготовку в модалке и может её переписать.
 */
class TournamentPushTextTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{title: string, body: string}> */
    private array $pushed = [];

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendMulticastToTokens')
            ->andReturnUsing(function ($tokens, $title, $body, $data = []) {
                $this->pushed[] = ['title' => $title, 'body' => $body];
                return true;
            });
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function makeTournament(): Tournament
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);

        return Tournament::create([
            'club_id' => $club->id, 'name' => 'Вечерний турнир', 'type' => 'americano',
            'status' => 'open', 'start_date' => '2026-08-20 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
    }

    private function makePlayerWithDevice(): User
    {
        $user = User::factory()->create(['role' => 'player', 'city' => 'Алматы', 'level' => 3.0]);
        DeviceToken::create(['user_id' => $user->id, 'token' => 'token-' . $user->id, 'platform' => 'android']);

        return $user;
    }

    public function test_custom_text_reaches_push_and_bell(): void
    {
        $this->fakePush();
        $tournament = $this->makeTournament();
        $this->makePlayerWithDevice();

        app(TournamentPushService::class)->send($tournament, 'Осталось 2 места!', 'Успей записаться до 18:00');

        $this->assertSame('Осталось 2 места!', $this->pushed[0]['title']);
        $this->assertSame('Успей записаться до 18:00', $this->pushed[0]['body']);

        $saved = Notification::first();
        $this->assertSame('Осталось 2 места!', $saved->title);
        $this->assertSame('Успей записаться до 18:00', $saved->body);
    }

    public function test_without_text_default_is_used(): void
    {
        // Мобильная админка шлёт без текста — заготовка должна работать как раньше.
        $this->fakePush();
        $tournament = $this->makeTournament();
        $this->makePlayerWithDevice();

        app(TournamentPushService::class)->send($tournament);

        $this->assertSame('Новый турнир!', $this->pushed[0]['title']);
        $this->assertStringContainsString('Вечерний турнир', $this->pushed[0]['body']);
        $this->assertStringContainsString('20.08.2026', $this->pushed[0]['body']);
    }

    public function test_blank_text_falls_back_to_default(): void
    {
        // Пустые строки из формы не должны превратиться в пустой пуш.
        $this->fakePush();
        $tournament = $this->makeTournament();
        $this->makePlayerWithDevice();

        app(TournamentPushService::class)->send($tournament, '   ', '');

        $this->assertSame('Новый турнир!', $this->pushed[0]['title']);
        $this->assertStringContainsString('Вечерний турнир', $this->pushed[0]['body']);
    }

    public function test_default_text_is_exposed_for_the_form(): void
    {
        // Модалка показывает ту же заготовку, что уйдёт при отправке.
        $tournament = $this->makeTournament();
        $service = app(TournamentPushService::class);

        $this->assertSame('Новый турнир!', $service->defaultTitle());
        $this->assertSame('Вечерний турнир — 20.08.2026 19:00', $service->defaultBody($tournament));
    }

    public function test_controller_passes_text_from_form(): void
    {
        $this->fakePush();
        $tournament = $this->makeTournament();
        $this->makePlayerWithDevice();

        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($tournament->club_id);

        $this->actingAs($admin)
            ->post(route('club.tournaments.sendPush', $tournament), [
                'push_title' => 'Свободно 2 места',
                'push_body' => 'Начало в 19:00, приходите',
            ])
            ->assertRedirect();

        $this->assertSame('Свободно 2 места', $this->pushed[0]['title']);
        $this->assertSame('Начало в 19:00, приходите', $this->pushed[0]['body']);
    }

    public function test_list_page_carries_default_text_for_the_modal(): void
    {
        $tournament = $this->makeTournament();
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($tournament->club_id);

        $this->actingAs($admin)
            ->get(route('club.tournaments.index'))
            ->assertOk()
            ->assertSee('Новый турнир!', false)
            ->assertSee('Вечерний турнир — 20.08.2026 19:00', false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
