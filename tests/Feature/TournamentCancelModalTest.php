<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отмена турнира в CRM спрашивает модалкой.
 *
 * Системный confirm ловился промахом мыши: турнир с полным составом улетал
 * в «Отменён», а поднимать его приходилось руками в базе.
 */
class TournamentCancelModalTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(string $status = 'open'): array
    {
        $club = Club::create(['name' => 'DAVAY PADEL', 'address' => 'А', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $tournament = Tournament::create([
            'club_id' => $club->id,
            'name' => '4й Этап ПАРНОЙ ЛИГИ',
            'type' => 'americano',
            'status' => $status,
            'start_date' => '2026-09-10 20:00:00',
            'min_level' => 2.5,
            'max_level' => 3.75,
            'max_participants' => 16,
        ]);

        return [$admin, $tournament];
    }

    public function test_кнопка_открывает_модалку_а_не_шлёт_форму(): void
    {
        [$admin, $tournament] = $this->tournament();

        $html = $this->actingAs($admin)
            ->get("/club/tournaments/{$tournament->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-bs-target="#cancelTournamentModal"', $html);
        $this->assertStringContainsString('id="cancelTournamentModal"', $html);
        $this->assertStringContainsString('Отменить турнир?', $html);
        $this->assertStringContainsString('Не отменять', $html);
        // Старый системный confirm ушёл вместе с формой в шапке.
        $this->assertStringNotContainsString("confirm('Отменить турнир?", $html);
    }

    public function test_у_отменённого_турнира_модалки_нет(): void
    {
        [$admin, $tournament] = $this->tournament('cancelled');

        $html = $this->actingAs($admin)
            ->get("/club/tournaments/{$tournament->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="cancelTournamentModal"', $html);
    }
}
