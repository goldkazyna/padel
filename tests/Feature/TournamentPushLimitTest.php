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
 * По одному турниру можно разослать push не больше двух раз: организаторы
 * жали колокольчик по многу раз, и одно и то же объявление прилетало людям
 * снова и снова.
 */
class TournamentPushLimitTest extends TestCase
{
    use RefreshDatabase;

    private int $pushCalls = 0;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendMulticastToTokens')
            ->andReturnUsing(function () {
                $this->pushCalls++;
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

    private function makeAdmin(Tournament $tournament): User
    {
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($tournament->club_id);

        return $admin;
    }

    public function test_первые_две_отправки_проходят_третья_нет(): void
    {
        $this->fakePush();
        $tournament = $this->makeTournament();
        $this->makePlayerWithDevice();
        $service = app(TournamentPushService::class);

        $this->assertSame(2, $service->remaining($tournament), 'сначала доступны обе отправки');

        $service->send($tournament);
        $this->assertSame(1, $service->remaining($tournament->fresh()));

        $service->send($tournament->fresh());
        $this->assertSame(0, $service->remaining($tournament->fresh()));

        $result = $service->send($tournament->fresh());
        $this->assertTrue($result['limit_reached'], 'третья отправка должна упереться в лимит');
        $this->assertSame(2, $this->pushCalls, 'на третий раз push слаться не должен');
    }

    public function test_после_лимита_не_появляется_новых_записей_в_колокольчике(): void
    {
        $this->fakePush();
        $tournament = $this->makeTournament();
        $this->makePlayerWithDevice();
        $service = app(TournamentPushService::class);

        $service->send($tournament);
        $service->send($tournament->fresh());
        $before = Notification::count();

        $service->send($tournament->fresh());

        $this->assertSame($before, Notification::count());
    }

    public function test_веб_кнопка_после_лимита_отвечает_ошибкой(): void
    {
        $this->fakePush();
        $tournament = $this->makeTournament();
        $this->makePlayerWithDevice();
        $admin = $this->makeAdmin($tournament);

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($admin)
                ->post(route('club.tournaments.sendPush', $tournament), ['push_title' => 'Т', 'push_body' => 'Б'])
                ->assertSessionHas('success');
        }

        $this->actingAs($admin)
            ->post(route('club.tournaments.sendPush', $tournament), ['push_title' => 'Т', 'push_body' => 'Б'])
            ->assertSessionHas('error');

        $this->assertSame(2, $this->pushCalls);
    }

    public function test_в_сообщении_видно_сколько_осталось(): void
    {
        $this->fakePush();
        $tournament = $this->makeTournament();
        $this->makePlayerWithDevice();
        $admin = $this->makeAdmin($tournament);

        $this->actingAs($admin)
            ->post(route('club.tournaments.sendPush', $tournament), [])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Осталось отправок: 1'));

        $this->actingAs($admin)
            ->post(route('club.tournaments.sendPush', $tournament->fresh()), [])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'последняя отправка'));
    }

    public function test_в_списке_турниров_счётчик_и_блокировка(): void
    {
        $this->fakePush();
        $tournament = $this->makeTournament();
        $this->makePlayerWithDevice();
        $admin = $this->makeAdmin($tournament);

        // Пока отправки есть — кнопка живая.
        $this->actingAs($admin)->get(route('club.tournaments.index'))
            ->assertSee('btn-push')
            ->assertDontSee('btn-push is-spent');

        app(TournamentPushService::class)->send($tournament);
        app(TournamentPushService::class)->send($tournament->fresh());

        // Лимит исчерпан — кнопка неактивна.
        $this->actingAs($admin)->get(route('club.tournaments.index'))
            ->assertSee('btn-push is-spent')
            ->assertSee('bi-bell-slash');
    }

    public function test_тестовый_режим_лимит_не_тратит(): void
    {
        // Отладка на своих номерах не должна съедать отправки, положенные игрокам.
        $this->fakePush();
        $tournament = $this->makeTournament();
        $player = $this->makePlayerWithDevice();
        $player->update(['phone' => '77011112233']);
        config(['mobile_app.push_test_phones' => '77011112233']);

        $service = app(TournamentPushService::class);
        $service->send($tournament);
        $service->send($tournament->fresh());
        $service->send($tournament->fresh());

        $this->assertSame(2, $service->remaining($tournament->fresh()));
        $this->assertSame(3, $this->pushCalls);
    }

    public function test_дубликат_турнира_получает_свои_две_отправки(): void
    {
        // Счётчик привязан к турниру, а не к названию: копия начинает с нуля.
        $this->fakePush();
        $tournament = $this->makeTournament();
        app(TournamentPushService::class)->send($tournament);
        app(TournamentPushService::class)->send($tournament->fresh());

        $copy = Tournament::create([
            'club_id' => $tournament->club_id, 'name' => $tournament->name, 'type' => 'americano',
            'status' => 'open', 'start_date' => '2026-08-27 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);

        $this->assertSame(2, app(TournamentPushService::class)->remaining($copy));
    }
}
