<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Лендинг трансляции: игрок делится ссылкой из live-экрана.
 *
 * Страница ничего не показывает по существу — её работа увести зрителя
 * в приложение на тот же live, а без приложения в магазин. Поэтому проверяем
 * deep-link, превью для мессенджеров и то, что вход открыт без авторизации.
 */
class LiveShareLandingTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(array $over = []): Tournament
    {
        $club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);

        return Tournament::factory()->create(array_merge([
            'club_id' => $club->id,
            'name' => 'Вечерний американо',
            'type' => 'americano',
            'status' => 'in_progress',
        ], $over));
    }

    public function test_ссылка_открыта_без_авторизации_и_ведёт_в_приложение(): void
    {
        $tournament = $this->tournament();

        $this->get("/live/{$tournament->id}")
            ->assertOk()
            ->assertSee('padelp://live/' . $tournament->id, false)
            ->assertSee('Вечерний американо');
    }

    public function test_идущий_турнир_помечен_как_прямой_эфир(): void
    {
        $tournament = $this->tournament();

        $response = $this->get("/live/{$tournament->id}")->assertOk();

        $response->assertSee('Идёт сейчас');
        $response->assertSee('Прямой эфир', false);
    }

    public function test_завершённый_турнир_зовут_на_результаты(): void
    {
        $tournament = $this->tournament(['status' => 'completed']);

        $response = $this->get("/live/{$tournament->id}")->assertOk();

        $response->assertDontSee('Идёт сейчас');
        $response->assertSee('Результаты', false);
    }

    public function test_превью_для_мессенджеров_несёт_клуб_и_формат(): void
    {
        $tournament = $this->tournament();

        $this->get("/live/{$tournament->id}")
            ->assertOk()
            ->assertSee('og:title', false)
            ->assertSee('Padel Sai', false)
            ->assertSee('Американо', false);
    }

    public function test_несуществующий_турнир_даёт_404(): void
    {
        $this->get('/live/999999')->assertNotFound();
    }
}
