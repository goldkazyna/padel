<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Support\GameArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Мои игры»: живые отдельно от архива, созданные отдельно от тех, где просто
 * играешь. Прошедшей игра становится сама — через несколько часов после конца,
 * даже если организатор не нажал «Завершить».
 */
class GameMyScopesTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::factory()->create();
        $this->me = User::factory()->create();
        Sanctum::actingAs($this->me);
    }

    private function game(array $over = [], bool $asPlayer = false, ?User $creator = null): Game
    {
        $creator ??= $this->me;

        $game = Game::factory()->create(array_merge([
            'creator_id' => $creator->id,
            'club_id' => $this->club->id,
            'capacity' => 4,
            'status' => 'open',
            'starts_at' => now('Asia/Almaty')->addDay(),
            'ends_at' => now('Asia/Almaty')->addDay()->addMinutes(90),
        ], $over));

        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $creator->id,
            'position' => 1,
            'status' => GamePlayer::STATUS_ACCEPTED,
        ]);

        if ($asPlayer) {
            GamePlayer::factory()->create([
                'game_id' => $game->id,
                'user_id' => $this->me->id,
                'position' => 2,
                'status' => GamePlayer::STATUS_ACCEPTED,
            ]);
        }

        return $game;
    }

    private function ids(array $query = []): array
    {
        return collect(
            $this->getJson('/api/mobile/games/my?' . http_build_query($query))
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();
    }

    public function test_played_game_moves_to_archive_by_itself(): void
    {
        $upcoming = $this->game();
        // Кончилась вчера — организатор ничего не нажимал.
        $old = $this->game([
            'starts_at' => now('Asia/Almaty')->subDay(),
            'ends_at' => now('Asia/Almaty')->subDay()->addMinutes(90),
        ]);
        // Кончилась час назад: ещё вносят счёт, в архив рано.
        $justEnded = $this->game([
            'starts_at' => now('Asia/Almaty')->subHours(2),
            'ends_at' => now('Asia/Almaty')->subHour(),
        ]);

        $live = $this->ids();
        $this->assertContains($upcoming->id, $live);
        $this->assertContains($justEnded->id, $live, 'час после конца — ещё живая');
        $this->assertNotContains($old->id, $live);

        $archive = $this->ids(['scope' => 'archive']);
        $this->assertContains($old->id, $archive);
        $this->assertNotContains($upcoming->id, $archive);
    }

    public function test_finished_and_cancelled_are_archive(): void
    {
        $finished = $this->game(['status' => 'finished']);
        $cancelled = $this->game(['status' => 'cancelled']);

        $live = $this->ids();
        $this->assertEmpty(array_intersect([$finished->id, $cancelled->id], $live));

        $archive = $this->ids(['scope' => 'archive']);
        $this->assertContains($finished->id, $archive);
        $this->assertContains($cancelled->id, $archive, 'отменённую тоже надо найти');
    }

    public function test_created_and_playing_split(): void
    {
        $mine = $this->game();
        $someoneElse = $this->game(asPlayer: true, creator: User::factory()->create());

        $created = $this->ids(['role' => 'creator']);
        $this->assertSame([$mine->id], $created);

        $playing = $this->ids(['role' => 'player']);
        $this->assertSame([$someoneElse->id], $playing);
    }

    public function test_grace_is_four_hours(): void
    {
        $this->assertSame(4, GameArchive::GRACE_HOURS);
    }
}
