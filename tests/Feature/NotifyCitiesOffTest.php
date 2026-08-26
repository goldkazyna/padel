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
 * Город можно выключить в настройках: объявления клубов этого города
 * не приходят ни пушем, ни в колокольчик.
 */
class NotifyCitiesOffTest extends TestCase
{
    use RefreshDatabase;

    private array $sentTokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendMulticastToTokens')->andReturnUsing(function ($tokens) {
            $this->sentTokens = array_merge($this->sentTokens, $tokens);

            return true;
        });
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function tournamentIn(string $city): Tournament
    {
        $club = Club::create(['name' => 'Клуб ' . $city, 'address' => 'А', 'city' => $city]);

        return Tournament::create([
            'club_id' => $club->id, 'name' => 'Турнир ' . $city, 'type' => 'americano',
            'status' => 'open', 'start_date' => '2026-09-01 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
    }

    private function player(string $city, string $token, array $citiesOff = []): User
    {
        $user = User::factory()->create([
            'role' => 'player', 'city' => $city, 'level' => 3.0,
            'notify_cities_off' => $citiesOff,
        ]);
        DeviceToken::create(['user_id' => $user->id, 'token' => $token, 'platform' => 'android']);

        return $user;
    }

    public function test_выключенный_город_не_присылает_объявлений(): void
    {
        $muted = $this->player('Алматы', 'token-muted', ['Алматы']);
        $normal = $this->player('Алматы', 'token-normal');

        app(TournamentPushService::class)->send($this->tournamentIn('Алматы'));

        $this->assertNotContains('token-muted', $this->sentTokens, 'город выключен — пуша нет');
        $this->assertContains('token-normal', $this->sentTokens);
        $this->assertSame(0, Notification::where('user_id', $muted->id)->count(),
            'и в колокольчике пусто: иначе смысл выключения теряется');
        $this->assertSame(1, Notification::where('user_id', $normal->id)->count());
    }

    public function test_другой_город_не_затрагивается(): void
    {
        // Выключил Алматы, но живёт в Астане — астанинские турниры приходят.
        $this->player('Астана', 'token-astana', ['Алматы']);

        app(TournamentPushService::class)->send($this->tournamentIn('Астана'));

        $this->assertContains('token-astana', $this->sentTokens);
    }

    public function test_пустой_список_ничего_не_меняет(): void
    {
        $this->player('Алматы', 'token-empty', []);

        app(TournamentPushService::class)->send($this->tournamentIn('Алматы'));

        $this->assertContains('token-empty', $this->sentTokens);
    }

    public function test_талдыкорган_принимается_как_город(): void
    {
        $user = $this->player('Талдыкорган', 'token-taldy');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/mobile/notifications/settings', ['notify_cities_off' => ['Талдыкорган']])
            ->assertOk();

        $this->assertSame(['Талдыкорган'], $user->fresh()->notify_cities_off);

        app(TournamentPushService::class)->send($this->tournamentIn('Талдыкорган'));
        $this->assertNotContains('token-taldy', $this->sentTokens);
    }

    public function test_настройки_отдают_города_и_выбор_пользователя(): void
    {
        $user = $this->player('Алматы', 'token-list', ['Шымкент']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/mobile/notifications/settings')
            ->assertOk();

        $this->assertSame(['Шымкент'], $response->json('notify_cities_off'));
        $this->assertContains('Талдыкорган', $response->json('cities'), 'новый город есть в списке');
        $this->assertContains('Алматы', $response->json('cities'));
    }

    public function test_несуществующий_город_не_сохраняется(): void
    {
        $user = $this->player('Алматы', 'token-bad');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/mobile/notifications/settings', ['notify_cities_off' => ['Париж']])
            ->assertStatus(422);
    }
}
