<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Часы игры — местные (Алматы), «сейчас» тоже должно быть местным.
 *
 * app.timezone у нас UTC, а в базе лежат часы клуба. Сравнение с UTC-шным
 * now() давало разницу в пять часов: начавшаяся игра ещё полдня висела
 * в ленте как предстоящая, а создать игру можно было задним числом.
 */
class GameAlmatyTimeTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
        $this->user = User::factory()->create(['level' => 3.0]);
    }

    /** Игра с временем в местных часах, как её пишет приложение. */
    private function game(string $startsAt, string $endsAt, array $extra = []): Game
    {
        return Game::create(array_merge([
            'creator_id' => $this->user->id,
            'club_id' => $this->club->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'type' => 'friendly',
            'visibility' => 'public',
            'format' => 'americano',
            'capacity' => 4,
            'status' => Game::STATUS_OPEN,
        ], $extra));
    }

    public function test_начавшаяся_игра_уходит_из_ленты(): void
    {
        $local = now('Asia/Almaty');

        $started = $this->game(
            $local->copy()->subHours(2)->format('Y-m-d H:i:s'),
            $local->copy()->subHour()->format('Y-m-d H:i:s'),
        );
        $upcoming = $this->game(
            $local->copy()->addHours(3)->format('Y-m-d H:i:s'),
            $local->copy()->addHours(4)->format('Y-m-d H:i:s'),
        );

        Sanctum::actingAs($this->user);
        $ids = collect($this->getJson('/api/mobile/games')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($upcoming->id, $ids);
        $this->assertNotContains($started->id, $ids, 'игра началась два часа назад — ей не место в ленте');
    }

    public function test_игру_задним_числом_не_создать(): void
    {
        $local = now('Asia/Almaty');

        Sanctum::actingAs($this->user);
        $this->postJson('/api/mobile/games', [
            'club_id' => $this->club->id,
            // Два часа назад по местным часам — но «впереди» по UTC.
            'starts_at' => $local->copy()->subHours(2)->format('Y-m-d\TH:i:s'),
            'ends_at' => $local->copy()->subHour()->format('Y-m-d\TH:i:s'),
            'type' => 'friendly',
            'visibility' => 'public',
            'format' => 'americano',
            'capacity' => 4,
        ])->assertStatus(422)->assertJsonValidationErrors('starts_at');
    }

    public function test_ссылка_перестаёт_работать_после_начала(): void
    {
        $local = now('Asia/Almaty');

        $game = $this->game(
            $local->copy()->subHour()->format('Y-m-d H:i:s'),
            $local->copy()->addHour()->format('Y-m-d H:i:s'),
            [
                'share_token' => 'tok123',
                'share_expires_at' => $local->copy()->subHour()->format('Y-m-d H:i:s'),
            ],
        );

        $this->assertFalse($game->shareLinkActive(), 'срок ссылки вышел час назад');
    }
}
