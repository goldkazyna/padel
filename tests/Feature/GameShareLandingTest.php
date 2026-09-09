<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Лендинг игры — как у турнира и лиги: ссылкой делятся в WhatsApp или
 * телеграме, там она разворачивается в карточку, а по тапу открывает игру
 * в приложении.
 */
class GameShareLandingTest extends TestCase
{
    use RefreshDatabase;

    private function game(array $over = []): Game
    {
        $club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        $creator = User::factory()->create();

        $game = Game::factory()->create(array_merge([
            'creator_id' => $creator->id,
            'club_id' => $club->id,
            'capacity' => 4,
            'status' => 'open',
            'format' => 'americano',
            'price' => 6500,
            // Часы клуба лежат как есть: 18:00 — это 18:00 в Алматы.
            'starts_at' => '2026-09-24 18:00:00',
            'ends_at' => '2026-09-24 19:30:00',
        ], $over));

        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $creator->id,
            'position' => 1,
            'status' => GamePlayer::STATUS_ACCEPTED,
        ]);

        return $game;
    }

    public function test_страница_открывается_и_ведёт_в_приложение(): void
    {
        $game = $this->game();

        $this->get("/g/{$game->id}")
            ->assertOk()
            ->assertSee('Padel Hills')
            ->assertSee('24 сентября, 18:00')
            ->assertSee("padelp://game/{$game->id}", false);
    }

    public function test_время_не_сдвигается(): void
    {
        $game = $this->game();

        // Классическая ошибка: показать 23:00 предыдущего дня, приняв часы
        // клуба за UTC. Правило — в docs/SYSTEM_RULES.md.
        $this->get("/g/{$game->id}")
            ->assertOk()
            ->assertDontSee('23:00')
            ->assertDontSee('13:00');
    }

    public function test_превью_для_мессенджера_заполнено(): void
    {
        $game = $this->game();

        $html = $this->get("/g/{$game->id}")->assertOk()->getContent();

        $this->assertStringContainsString('og:title', $html);
        $this->assertStringContainsString('og:image', $html);
        $this->assertStringContainsString('свободно мест: 3', $html);
    }

    public function test_заполненная_игра_не_обещает_мест(): void
    {
        $game = $this->game(['capacity' => 1, 'status' => 'full']);

        $html = $this->get("/g/{$game->id}")->assertOk()->getContent();

        $this->assertStringContainsString('мест нет', $html);
        $this->assertStringNotContainsString('Свободно мест', $html);
    }
}
