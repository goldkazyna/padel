<?php

namespace Tests\Feature;

use App\Models\AmericanoMatch;
use App\Models\AmericanoRound;
use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Notification;
use App\Models\PlayerFollow;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Амигос: связи между игроками и их активность.
 *
 * Связь односторонняя: добавил — видишь. Заявок и одобрений нет намеренно,
 * очередь одобрений мы уже проходили на играх, её не разбирали никогда.
 */
class AmigosTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);

        $this->me = User::factory()->create(['name' => 'Денис']);
        $this->club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
    }

    private function player(string $name): User
    {
        return User::factory()->create(['name' => $name, 'rating' => 3000, 'level' => 3.0]);
    }

    private function follow(User $target): void
    {
        PlayerFollow::create([
            'follower_id' => $this->me->id,
            'following_id' => $target->id,
            'created_at' => now(),
        ]);
    }

    /** Турнир, в котором игрок сейчас на корте. */
    private function playingTournament(User $player): Tournament
    {
        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'in_progress',
            'type' => 'americano',
            'name' => 'Вечерний американо',
        ]);
        $tournament->participants()->attach($player->id, ['status' => 'registered']);

        return $tournament;
    }

    public function test_добавление_односторонее_и_без_одобрения(): void
    {
        $other = $this->player('Асхат');
        Sanctum::actingAs($this->me);

        $this->postJson("/api/mobile/amigos/{$other->id}")
            ->assertOk()
            ->assertJsonPath('is_amigo', true)
            ->assertJsonPath('mutual', false);

        // Никаких «заявок»: связь есть сразу.
        $this->assertDatabaseHas('player_follows', [
            'follower_id' => $this->me->id,
            'following_id' => $other->id,
        ]);
    }

    public function test_добавленному_приходит_уведомление(): void
    {
        $other = $this->player('Асхат');
        Sanctum::actingAs($this->me);

        $this->postJson("/api/mobile/amigos/{$other->id}")->assertOk();

        $notification = Notification::where('user_id', $other->id)->latest('id')->first();
        $this->assertSame('amigo_added', $notification->type);
        $this->assertStringContainsString('Денис', $notification->body);
    }

    public function test_повторное_добавление_не_ошибка_и_не_шлёт_второе_уведомление(): void
    {
        $other = $this->player('Асхат');
        Sanctum::actingAs($this->me);

        $this->postJson("/api/mobile/amigos/{$other->id}")->assertOk();
        $this->postJson("/api/mobile/amigos/{$other->id}")->assertOk();

        $this->assertSame(1, PlayerFollow::count());
        $this->assertSame(1, Notification::where('user_id', $other->id)->count());
    }

    public function test_себя_добавить_нельзя(): void
    {
        Sanctum::actingAs($this->me);

        $this->postJson("/api/mobile/amigos/{$this->me->id}")->assertStatus(422);
    }

    public function test_взаимность_видна_обеим_сторонам(): void
    {
        $other = $this->player('Асхат');
        PlayerFollow::create([
            'follower_id' => $other->id, 'following_id' => $this->me->id, 'created_at' => now(),
        ]);

        Sanctum::actingAs($this->me);

        $this->postJson("/api/mobile/amigos/{$other->id}")
            ->assertOk()
            ->assertJsonPath('mutual', true);
    }

    public function test_список_показывает_кто_сейчас_на_корте(): void
    {
        $playing = $this->player('Асхат');
        $this->follow($playing);
        $this->playingTournament($playing);

        Sanctum::actingAs($this->me);

        $response = $this->getJson('/api/mobile/amigos')->assertOk();

        $response->assertJsonPath('playing_count', 1);
        $response->assertJsonPath('amigos.0.status.kind', 'playing');
        $this->assertStringContainsString('Вечерний американо', $response->json('amigos.0.status.subtitle'));
    }

    public function test_играющие_идут_первыми(): void
    {
        $quiet = $this->player('Aбдулла');   // по алфавиту был бы первым
        $playing = $this->player('Ярослав');
        $this->follow($quiet);
        $this->follow($playing);
        $this->playingTournament($playing);

        Sanctum::actingAs($this->me);

        $names = collect($this->getJson('/api/mobile/amigos')->json('amigos'))->pluck('name')->all();

        $this->assertSame(['Ярослав', 'Aбдулла'], $names, 'на корте — выше алфавита');
    }

    public function test_ближайший_турнир_и_поиск_игроков_попадают_в_статус(): void
    {
        $soon = $this->player('Диана');
        $looking = $this->player('Ержан');
        $this->follow($soon);
        $this->follow($looking);

        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'open',
            'type' => 'americano',
            'start_date' => now()->addHours(3),
        ]);
        $tournament->participants()->attach($soon->id, ['status' => 'registered']);

        $game = Game::factory()->create([
            'club_id' => $this->club->id,
            'creator_id' => $looking->id,
            'status' => Game::STATUS_OPEN,
            'visibility' => Game::VISIBILITY_PUBLIC,
            'starts_at' => now()->addHours(5),
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $looking->id,
            'status' => GamePlayer::STATUS_ACCEPTED,
            'position' => 1,
        ]);

        Sanctum::actingAs($this->me);
        $rows = collect($this->getJson('/api/mobile/amigos')->json('amigos'))->keyBy('name');

        $this->assertSame('soon', $rows['Диана']['status']['kind']);
        $this->assertSame('looking', $rows['Ержан']['status']['kind']);
    }

    public function test_приватная_игра_в_активность_не_идёт(): void
    {
        $player = $this->player('Тихий');
        $this->follow($player);

        $game = Game::factory()->create([
            'club_id' => $this->club->id,
            'creator_id' => $player->id,
            'status' => Game::STATUS_IN_PROGRESS,
            'visibility' => Game::VISIBILITY_PRIVATE,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'status' => GamePlayer::STATUS_ACCEPTED,
            'position' => 1,
        ]);

        Sanctum::actingAs($this->me);

        $this->getJson('/api/mobile/amigos')
            ->assertOk()
            ->assertJsonPath('playing_count', 0)
            ->assertJsonPath('amigos.0.status', null);
    }

    public function test_скрытый_из_рейтинга_показан_но_без_активности(): void
    {
        $hidden = $this->player('Невидимка');
        $hidden->update(['hidden_from_rating' => true]);
        $this->follow($hidden);
        $this->playingTournament($hidden);

        Sanctum::actingAs($this->me);

        $this->getJson('/api/mobile/amigos')
            ->assertOk()
            ->assertJsonPath('amigos.0.name', 'Невидимка')
            ->assertJsonPath('amigos.0.status', null);
    }

    public function test_вкладка_меня_добавили(): void
    {
        $fan = $this->player('Поклонник');
        PlayerFollow::create([
            'follower_id' => $fan->id, 'following_id' => $this->me->id, 'created_at' => now(),
        ]);

        Sanctum::actingAs($this->me);

        $this->getJson('/api/mobile/amigos/followers')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('followers.0.name', 'Поклонник')
            ->assertJsonPath('followers.0.mutual', false);
    }

    public function test_кандидаты_берутся_из_тех_с_кем_играли(): void
    {
        $partner = $this->player('Напарник');
        $stranger = $this->player('Незнакомец');

        // Три матча в паре — минимум, с которого человек считается партнёром.
        $tournament = Tournament::factory()->create([
            'club_id' => $this->club->id,
            'status' => 'completed',
            'type' => 'americano',
        ]);
        $group = TournamentGroup::create(['tournament_id' => $tournament->id, 'name' => 'A']);
        foreach (range(1, 3) as $i) {
            $round = AmericanoRound::create([
                'tournament_group_id' => $group->id,
                'round_number' => $i,
                'status' => 'completed',
            ]);
            AmericanoMatch::create([
                'americano_round_id' => $round->id,
                'court_number' => 1,
                'team1_player1_id' => $this->me->id,
                'team1_player2_id' => $partner->id,
                'team2_player1_id' => $stranger->id,
                'team2_player2_id' => $this->player('Соперник ' . $i)->id,
                'team1_score' => 21,
                'team2_score' => 15,
                'status' => 'completed',
            ]);
        }

        Sanctum::actingAs($this->me);

        $candidates = $this->getJson('/api/mobile/amigos/candidates')->assertOk()->json('candidates');
        $names = array_column($candidates, 'name');

        $this->assertContains('Напарник', $names);
        $this->assertSame(3, collect($candidates)->firstWhere('name', 'Напарник')['games_together']);
    }

    public function test_кандидат_уже_добавленный_в_список_не_попадает(): void
    {
        $partner = $this->player('Напарник');
        $this->follow($partner);

        Sanctum::actingAs($this->me);

        $names = array_column($this->getJson('/api/mobile/amigos/candidates')->json('candidates'), 'name');
        $this->assertNotContains('Напарник', $names);
    }

    public function test_заблокированный_исчезает_из_списка_и_связь_рвётся(): void
    {
        $other = $this->player('Неприятный');
        $this->follow($other);
        PlayerFollow::create([
            'follower_id' => $other->id, 'following_id' => $this->me->id, 'created_at' => now(),
        ]);

        Sanctum::actingAs($this->me);

        $this->postJson("/api/mobile/users/{$other->id}/block")->assertOk();

        $this->assertSame(0, PlayerFollow::count(), 'блокировка рвёт связь в обе стороны');
        $this->getJson('/api/mobile/amigos')->assertOk()->assertJsonPath('amigos', []);
        $this->assertTrue(UserBlock::betweenExists($this->me->id, $other->id));
    }

    public function test_заблокированного_нельзя_добавить(): void
    {
        $other = $this->player('Неприятный');
        UserBlock::create([
            'user_id' => $other->id, 'blocked_user_id' => $this->me->id, 'created_at' => now(),
        ]);

        Sanctum::actingAs($this->me);

        $this->postJson("/api/mobile/amigos/{$other->id}")->assertStatus(422);
    }

    public function test_убрать_из_амигос(): void
    {
        $other = $this->player('Асхат');
        $this->follow($other);

        Sanctum::actingAs($this->me);

        $this->deleteJson("/api/mobile/amigos/{$other->id}")
            ->assertOk()
            ->assertJsonPath('is_amigo', false);

        $this->assertSame(0, PlayerFollow::count());
    }

    public function test_профиль_отдаёт_карточку_амигос(): void
    {
        $playing = $this->player('Асхат');
        $this->follow($playing);
        $this->playingTournament($playing);

        Sanctum::actingAs($this->me);

        $this->getJson('/api/mobile/profile')
            ->assertOk()
            ->assertJsonPath('amigos.count', 1)
            ->assertJsonPath('amigos.playing_count', 1)
            ->assertJsonPath('amigos.playing_preview.0.name', 'Асхат')
            ->assertJsonPath('amigos.unread_messages', 0);
    }

    public function test_поиск_находит_игрока_по_имени(): void
    {
        $this->player('Ержан Рахимов');
        $this->player('Диана Смагулова');

        Sanctum::actingAs($this->me);

        $found = $this->getJson('/api/mobile/amigos/search?q=Ержан')->assertOk()->json('players');

        $names = array_column($found, 'name');
        $this->assertContains('Ержан Рахимов', $names);
        $this->assertNotContains('Диана Смагулова', $names);
        $this->assertFalse($found[0]['is_amigo']);
    }

    public function test_поиск_понимает_другой_алфавит(): void
    {
        // Половина игроков записана латиницей, ищут их кириллицей — и наоборот.
        $this->player('Yerzhan Rakhimov');

        Sanctum::actingAs($this->me);

        $found = $this->getJson('/api/mobile/amigos/search?q=Ержан')->assertOk()->json('players');

        $this->assertNotEmpty($found, 'поиск должен находить и латиницу');
    }

    public function test_поиск_помечает_уже_добавленных(): void
    {
        $other = $this->player('Асхат Ким');
        $this->follow($other);

        Sanctum::actingAs($this->me);

        $found = $this->getJson('/api/mobile/amigos/search?q=Асхат')->assertOk()->json('players');

        $this->assertTrue($found[0]['is_amigo'], 'иначе кнопка предложит добавить дважды');
    }

    public function test_поиск_не_показывает_себя_и_заблокированных(): void
    {
        $blocked = $this->player('Неприятный Тип');
        UserBlock::create([
            'user_id' => $this->me->id,
            'blocked_user_id' => $blocked->id,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->me);

        $names = array_column(
            $this->getJson('/api/mobile/amigos/search?q=и')->assertOk()->json('players'),
            'name'
        );

        $this->assertNotContains('Неприятный Тип', $names);
        $this->assertNotContains('Денис', $names, 'себя в поиске быть не должно');
    }

    public function test_короткий_запрос_ничего_не_ищет(): void
    {
        $this->player('Асхат Ким');

        Sanctum::actingAs($this->me);

        $this->getJson('/api/mobile/amigos/search?q=А')
            ->assertOk()
            ->assertJsonPath('players', []);
    }

    public function test_лента_показывает_события_своих(): void
    {
        $playing = $this->player('Асхат');
        $this->follow($playing);
        $this->playingTournament($playing);

        Sanctum::actingAs($this->me);

        $events = $this->getJson('/api/mobile/amigos/feed')->assertOk()->json('events');

        $this->assertNotEmpty($events);
        $this->assertSame('playing', $events[0]['kind']);
        $this->assertSame('Асхат', $events[0]['player']['name']);
    }
}
