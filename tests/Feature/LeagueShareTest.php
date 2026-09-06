<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Лендинг лиги — как у турнира: ссылкой делятся в мессенджере, там она должна
 * развернуться в карточку, а по тапу открыть лигу в приложении.
 */
class LeagueShareTest extends TestCase
{
    use RefreshDatabase;

    private function league(array $over = []): League
    {
        $club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);

        return League::create(array_merge([
            'club_id' => $club->id,
            'name' => 'Осенняя лига',
            'status' => 'open',
            'start_date' => '2026-09-01',
            'end_date' => '2026-11-30',
        ], $over));
    }

    public function test_страница_открывается_и_ведёт_в_приложение(): void
    {
        $league = $this->league();

        $this->get("/l/{$league->id}")
            ->assertOk()
            ->assertSee('Осенняя лига')
            ->assertSee('Padel Sai')
            ->assertSee("padelp://league/{$league->id}", false)
            // Даты периода — на странице и в описании превью.
            ->assertSee('01.09.2026 — 30.11.2026', false);
    }

    public function test_превью_для_мессенджера_заполнено(): void
    {
        $league = $this->league();

        $html = $this->get("/l/{$league->id}")->assertOk()->getContent();

        $this->assertStringContainsString('og:title', $html);
        $this->assertStringContainsString('Лига «Осенняя лига»', $html);
        $this->assertStringContainsString('og:image', $html);
        $this->assertStringContainsString(url("/l/{$league->id}"), $html);
    }

    public function test_лиги_без_дат_не_ломают_страницу(): void
    {
        $league = $this->league([
            'status' => 'in_progress',
            'start_date' => null,
            'end_date' => null,
        ]);

        $this->get("/l/{$league->id}")
            ->assertOk()
            ->assertSee('Идёт');
    }

    public function test_несуществующая_лига_это_404(): void
    {
        $this->get('/l/999999')->assertNotFound();
    }
}
