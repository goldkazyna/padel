<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Описание турнира правится в любом статусе, включая завершённый.
 * Остальные поля после старта по-прежнему закрыты: они влияют на сыгранное.
 */
class TournamentDescriptionEditTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function tournament(string $status = 'completed'): Tournament
    {
        return Tournament::create([
            'club_id' => $this->club->id, 'name' => 'Вечерний турнир', 'type' => 'americano',
            'status' => $status, 'start_date' => '2026-08-20 19:00:00',
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
            'description' => 'Старое описание',
        ]);
    }

    private function save(Tournament $tournament, $description, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->patchJson(
            "/api/mobile/admin/tournaments/{$tournament->id}/description",
            ['description' => $description]
        );
    }

    public function test_описание_завершённого_турнира_меняется(): void
    {
        $tournament = $this->tournament('completed');

        $this->save($tournament, 'Итоги: победил Ерлан, спасибо всем!')
            ->assertOk()
            ->assertJson(['success' => true, 'description' => 'Итоги: победил Ерлан, спасибо всем!']);

        $this->assertSame('Итоги: победил Ерлан, спасибо всем!', $tournament->fresh()->description);
    }

    public function test_остальные_поля_не_трогаются(): void
    {
        $tournament = $this->tournament('completed');

        $this->save($tournament, 'Другое описание')->assertOk();

        $fresh = $tournament->fresh();
        $this->assertSame('Вечерний турнир', $fresh->name, 'название остаётся прежним');
        $this->assertSame('completed', $fresh->status);
        $this->assertSame(8, (int) $fresh->max_participants);
    }

    public function test_пустое_описание_очищает_поле(): void
    {
        $tournament = $this->tournament('completed');

        $this->save($tournament, '   ')->assertOk();

        $this->assertNull($tournament->fresh()->description, 'пробелы — это пустое описание, а не текст');
    }

    public function test_работает_и_для_идущего_турнира(): void
    {
        $tournament = $this->tournament('in_progress');

        $this->save($tournament, 'Играем на третьем корте')->assertOk();

        $this->assertSame('Играем на третьем корте', $tournament->fresh()->description);
    }

    public function test_слишком_длинное_описание_отклоняется(): void
    {
        $tournament = $this->tournament('completed');

        $this->save($tournament, str_repeat('а', 5001))->assertStatus(422);

        $this->assertSame('Старое описание', $tournament->fresh()->description);
    }

    public function test_чужой_клуб_не_может_править(): void
    {
        $tournament = $this->tournament('completed');

        $other = User::factory()->create(['role' => 'club_admin']);
        $otherClub = Club::create(['name' => 'Другой', 'address' => 'Б', 'city' => 'Астана']);
        $other->adminClubs()->attach($otherClub->id);

        $this->save($tournament, 'Взлом', $other)->assertForbidden();

        $this->assertSame('Старое описание', $tournament->fresh()->description);
    }

    public function test_обычный_игрок_не_может_править(): void
    {
        $tournament = $this->tournament('completed');
        $player = User::factory()->create(['role' => 'player']);

        $this->save($tournament, 'Моё описание', $player)->assertForbidden();
    }

    public function test_полное_редактирование_после_старта_по_прежнему_закрыто(): void
    {
        $tournament = $this->tournament('completed');

        $this->actingAs($this->admin, 'sanctum')->putJson(
            "/api/mobile/admin/tournaments/{$tournament->id}",
            [
                'name' => 'Новое имя', 'start_date' => '2026-09-01 19:00:00',
                'min_level' => 1, 'max_level' => 5, 'max_participants' => 12,
            ]
        )->assertStatus(422);

        $this->assertSame('Вечерний турнир', $tournament->fresh()->name);
    }
}
