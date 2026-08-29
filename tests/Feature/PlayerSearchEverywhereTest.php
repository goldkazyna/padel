<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Поиск игрока работает одинаково во всех местах: пишешь «Денис» —
 * находятся и Денис, и Denis, причём своё написание идёт первым.
 *
 * Раньше умный поиск был только в рейтинге и в списке пользователей,
 * а при добавлении игрока в турнир или поиске партнёра — обычный LIKE.
 */
class PlayerSearchEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;
    private User $cyrillic;
    private User $latin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);

        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        $this->cyrillic = User::factory()->create([
            'role' => 'player', 'name' => 'Денис Дудников', 'phone' => '77771112233', 'level' => 3.0,
        ]);
        $this->latin = User::factory()->create([
            'role' => 'player', 'name' => 'Denis Dudnikov', 'phone' => '77774445566', 'level' => 3.0,
        ]);
    }

    private function tournament(string $type = 'americano', string $status = 'open'): Tournament
    {
        return Tournament::create([
            'club_id' => $this->club->id, 'name' => 'Турнир', 'type' => $type,
            'status' => $status, 'start_date' => '2026-09-01 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
    }

    public function test_админ_добавляет_игрока_в_турнир_из_приложения(): void
    {
        $tournament = $this->tournament();

        $response = $this->actingAs($this->admin, 'sanctum')->getJson(
            "/api/mobile/admin/tournaments/{$tournament->id}/players/search?q=Денис"
        )->assertOk();

        $names = collect($response->json('players'))->pluck('name')->all();

        $this->assertContains('Денис Дудников', $names);
        $this->assertContains('Denis Dudnikov', $names, 'латиница тоже находится');
        $this->assertSame('Денис Дудников', $names[0], 'своё написание — первым');
    }

    public function test_поиск_партнёра_в_приложении(): void
    {
        $tournament = $this->tournament('team');
        $player = User::factory()->create(['role' => 'player', 'level' => 3.0]);

        // Ищем фамилию кириллицей: под SQLite (тестовая база) LIKE не
        // приводит регистр у кириллицы, в отличие от MySQL на проде, поэтому
        // проверяем направление, которое одинаково в обоих драйверах.
        $response = $this->actingAs($player, 'sanctum')->postJson(
            "/api/mobile/tournaments/{$tournament->id}/search-partner",
            ['query' => 'Дудников']
        )->assertOk();

        $names = collect($response->json('partners'))->pluck('name')->all();

        $this->assertContains('Денис Дудников', $names);
        $this->assertContains('Denis Dudnikov', $names, 'латиница находится по кириллице');
        $this->assertSame('Денис Дудников', $names[0], 'своё написание — первым');
    }

    public function test_поединки_ищут_и_по_имени(): void
    {
        $player = User::factory()->create(['role' => 'player', 'level' => 3.0]);

        $response = $this->actingAs($player, 'sanctum')
            ->postJson('/api/mobile/challenges/search-player', ['phone' => 'Денис'])
            ->assertOk();

        $names = collect($response->json('data') ?? $response->json('users') ?? [])
            ->map(fn ($u) => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')))
            ->all();

        $this->assertContains('Денис Дудников', $names, 'раньше по имени вообще не искалось');
        $this->assertContains('Denis Dudnikov', $names);
    }

    public function test_поединки_по_телефону_работают_как_раньше(): void
    {
        $player = User::factory()->create(['role' => 'player', 'level' => 3.0]);

        $this->actingAs($player, 'sanctum')
            ->postJson('/api/mobile/challenges/search-player', ['phone' => '7777111'])
            ->assertOk()
            ->assertJsonFragment(['first_name' => 'Денис']);
    }

    public function test_веб_добавление_игрока_в_турнир(): void
    {
        $tournament = $this->tournament();

        $response = $this->actingAs($this->admin)->get(
            route('club.tournaments.searchPlayers', $tournament) . '?q=Денис'
        )->assertOk();

        $names = collect($response->json())->pluck('name')->all();

        $this->assertContains('Денис Дудников', $names);
        $this->assertContains('Denis Dudnikov', $names);
        $this->assertSame('Денис Дудников', $names[0]);
    }

    public function test_поиск_по_телефону_никуда_не_делся(): void
    {
        $tournament = $this->tournament();

        $response = $this->actingAs($this->admin, 'sanctum')->getJson(
            "/api/mobile/admin/tournaments/{$tournament->id}/players/search?q=7777444"
        )->assertOk();

        $this->assertSame(
            ['Denis Dudnikov'],
            collect($response->json('players'))->pluck('name')->all()
        );
    }
}
