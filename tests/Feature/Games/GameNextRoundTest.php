<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameRound;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Раунды Американо набирают по кнопке, как в турнирном Флексе.
 *
 * Раньше старт сразу выкладывал всё расписание — ввосьмером это четырнадцать
 * строк, которые никто не доигрывает. Теперь при старте один раунд, дальше
 * «Следующий раунд», и играть можно сколько угодно: за пределами готовой
 * сетки расклад считает алгоритм.
 */
class GameNextRoundTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;
    private User $organizer;
    /** @var array<int,User> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $fcm = Mockery::mock(FCMNotificationService::class);
        $fcm->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $fcm);

        $club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
        $this->organizer = User::factory()->create(['level' => 3.0]);

        $this->game = Game::create([
            'creator_id' => $this->organizer->id,
            'club_id' => $club->id,
            'starts_at' => now('Asia/Almaty')->addHour()->format('Y-m-d H:i:s'),
            'ends_at' => now('Asia/Almaty')->addHours(2)->format('Y-m-d H:i:s'),
            'type' => 'friendly',
            'visibility' => 'public',
            'format' => 'americano',
            'capacity' => 4,
            'status' => Game::STATUS_OPEN,
        ]);

        $this->players = [$this->organizer];
        for ($i = 1; $i < 4; $i++) {
            $this->players[] = User::factory()->create(['level' => 3.0]);
        }
        foreach ($this->players as $pos => $u) {
            GamePlayer::create([
                'game_id' => $this->game->id,
                'user_id' => $u->id,
                'status' => GamePlayer::STATUS_ACCEPTED,
                'position' => $pos + 1,
            ]);
        }
        $this->game->update(['status' => Game::STATUS_FULL]);
    }

    private function start(): void
    {
        Sanctum::actingAs($this->organizer);
        $this->postJson("/api/mobile/games/{$this->game->id}/start")->assertOk();
    }

    private function scoreLastRound(int $a = 21, int $b = 15): void
    {
        // Связь rounds() уже сортирует по возрастанию — берём последний с конца.
        $round = $this->game->rounds()->get()->last();
        $round->update(['score_a' => $a, 'score_b' => $b, 'is_played' => true]);
    }

    public function test_старт_кладёт_только_первый_раунд(): void
    {
        $this->start();

        $this->assertSame(1, $this->game->rounds()->count(), 'при старте один раунд, а не всё расписание');
        $this->assertSame(1, $this->game->rounds()->first()->round_no);
    }

    public function test_кнопка_добавляет_следующий_раунд(): void
    {
        $this->start();
        $this->scoreLastRound();

        Sanctum::actingAs($this->organizer);
        $this->postJson("/api/mobile/games/{$this->game->id}/rounds/next")
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame(2, $this->game->rounds()->count());
        $this->assertSame(2, $this->game->rounds()->get()->last()->round_no);
    }

    public function test_без_счёта_следующий_раунд_не_дают(): void
    {
        $this->start();

        Sanctum::actingAs($this->organizer);
        $this->postJson("/api/mobile/games/{$this->game->id}/rounds/next")
            ->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(1, $this->game->rounds()->count());
    }

    public function test_играть_можно_дольше_готовой_сетки(): void
    {
        $this->start();

        // У четверых в таблице три раунда — добираем до восьми.
        for ($i = 2; $i <= 8; $i++) {
            $this->scoreLastRound();
            Sanctum::actingAs($this->organizer);
            $this->postJson("/api/mobile/games/{$this->game->id}/rounds/next")->assertOk();
        }

        $this->assertSame(8, $this->game->rounds()->count());

        // В каждом раунде четверо разных из состава.
        $ids = collect($this->players)->pluck('id')->all();
        foreach ($this->game->rounds as $round) {
            $four = array_merge($round->pair_a, $round->pair_b);
            $this->assertCount(4, array_unique($four), "раунд {$round->round_no}");
            foreach ($four as $uid) {
                $this->assertContains($uid, $ids);
            }
        }
    }

    public function test_чужой_раунд_не_добавит(): void
    {
        $this->start();
        $this->scoreLastRound();

        Sanctum::actingAs($this->players[1]);
        $this->postJson("/api/mobile/games/{$this->game->id}/rounds/next")->assertStatus(403);
    }

    public function test_таблица_приходит_в_виде_для_приложения(): void
    {
        $this->start();
        $this->scoreLastRound(21, 15);

        Sanctum::actingAs($this->organizer);
        $row = $this->getJson("/api/mobile/games/{$this->game->id}")
            ->assertOk()->json('data.americano_ranking.0');

        foreach (['position', 'id', 'name', 'points_for', 'points_against',
                  'matches_played', 'wins', 'losses', 'draws'] as $key) {
            $this->assertArrayHasKey($key, $row, "нет ключа $key");
        }
        $this->assertSame(1, $row['position']);
        $this->assertSame(21, $row['points_for']);
        $this->assertSame(1, $row['matches_played']);
    }
}
