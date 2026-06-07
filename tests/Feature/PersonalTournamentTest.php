<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Личные (приватные) турниры обычных игроков с грантом can_create_tournaments.
 */
class PersonalTournamentTest extends TestCase
{
    use RefreshDatabase;

    private function body(array $overrides = []): array
    {
        return array_merge([
            'type' => 'americano',
            'name' => 'Личный турнир',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1.0,
            'max_level' => 5.0,
            'max_participants' => 8,
            'status' => 'open',
        ], $overrides);
    }

    public function test_user_without_grant_cannot_create(): void
    {
        $user = User::factory()->create(['can_create_tournaments' => false]);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/personal/tournaments', $this->body())
            ->assertStatus(403);
    }

    public function test_granted_user_creates_personal_non_rated_clubless(): void
    {
        $user = User::factory()->create(['can_create_tournaments' => true]);
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/mobile/personal/tournaments', $this->body())
            ->assertOk()
            ->assertJsonPath('success', true);

        $id = $resp->json('tournament_id');
        $t = Tournament::find($id);

        $this->assertSame($user->id, (int) $t->creator_id);
        $this->assertNull($t->club_id, 'личный турнир без клуба');
        $this->assertFalse((bool) $t->is_rated, 'личный турнир всегда нерейтинговый');
        $this->assertTrue($t->isPersonal());
    }

    public function test_is_rated_forced_false_even_if_requested(): void
    {
        $user = User::factory()->create(['can_create_tournaments' => true]);
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/mobile/personal/tournaments',
            $this->body(['is_rated' => true]))->assertOk();

        $t = Tournament::find($resp->json('tournament_id'));
        $this->assertFalse((bool) $t->is_rated, 'is_rated принудительно false');
    }

    public function test_personal_tournament_hidden_from_public_index(): void
    {
        $creator = User::factory()->create(['can_create_tournaments' => true]);
        Sanctum::actingAs($creator);
        $resp = $this->postJson('/api/mobile/personal/tournaments', $this->body())->assertOk();
        $personalId = $resp->json('tournament_id');

        // Публичный список турниров
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        $list = $this->getJson('/api/mobile/tournaments')->assertOk()->json('tournaments');

        $ids = collect($list)->pluck('id')->all();
        $this->assertNotContains($personalId, $ids, 'личный турнир не в публичном списке');
    }

    public function test_creator_sees_personal_in_own_list(): void
    {
        $user = User::factory()->create(['can_create_tournaments' => true]);
        Sanctum::actingAs($user);
        $resp = $this->postJson('/api/mobile/personal/tournaments', $this->body())->assertOk();
        $id = $resp->json('tournament_id');

        $list = $this->getJson('/api/mobile/personal/tournaments')->assertOk()->json('tournaments');
        $this->assertContains($id, collect($list)->pluck('id')->all());
    }
}
