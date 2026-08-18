<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\JustPadelItPair;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Запись парой в мобильной админке — зеркало веба.
 */
class MobileAdminPairsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Клуб', 'address' => 'А']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);
    }

    private function tournament(array $over = []): Tournament
    {
        return Tournament::factory()->create(array_merge([
            'club_id' => $this->club->id,
            'type' => 'just_padel_it',
            'is_paired' => true,
            'pairing_mode' => 'admin',
            'status' => 'open',
            'max_participants' => 8,
        ], $over));
    }

    public function test_admin_adds_a_pair_from_the_app(): void
    {
        $t = $this->tournament();
        [$a, $b] = User::factory()->count(2)->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/pairs", [
            'player1_id' => $a->id,
            'player2_id' => $b->id,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('mode', 'format')
            ->assertJsonCount(1, 'pairs');

        $this->assertSame(2, $t->participants()->count());
    }

    /** При самостоятельной сборке пара ложится в команды турнира. */
    public function test_self_pairing_pair_goes_to_teams(): void
    {
        $t = $this->tournament(['pairing_mode' => 'self']);
        [$a, $b] = User::factory()->count(2)->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/pairs", [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ])
            ->assertOk()
            ->assertJsonPath('mode', 'teams')
            ->assertJsonCount(1, 'pairs');

        $this->assertSame(1, $t->teams()->count());
        $this->assertSame(0, JustPadelItPair::where('tournament_id', $t->id)->count());
    }

    public function test_state_lists_pairs_and_those_without_one(): void
    {
        $t = $this->tournament();
        [$a, $b] = User::factory()->count(2)->create();
        $solo = User::factory()->create(['name' => 'Mister Bekson']);
        $t->participants()->attach($solo->id, ['status' => 'registered']);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/pairs", [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ])->assertOk();

        $this->getJson("/api/mobile/admin/tournaments/{$t->id}/pairs")
            ->assertOk()
            ->assertJsonPath('supported', true)
            ->assertJsonCount(1, 'pairs')
            ->assertJsonCount(1, 'unpaired')
            ->assertJsonPath('unpaired.0.name', 'Mister Bekson');
    }

    public function test_player_cannot_be_in_two_pairs(): void
    {
        $t = $this->tournament();
        [$a, $b, $c] = User::factory()->count(3)->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/pairs", [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ])->assertOk();

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/pairs", [
            'player1_id' => $a->id, 'player2_id' => $c->id,
        ])->assertStatus(422);

        $this->assertSame(1, JustPadelItPair::where('tournament_id', $t->id)->count());
    }

    public function test_breaking_a_pair_keeps_the_players(): void
    {
        $t = $this->tournament();
        [$a, $b] = User::factory()->count(2)->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/pairs", [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ])->assertOk();
        $pair = JustPadelItPair::where('tournament_id', $t->id)->firstOrFail();

        $this->deleteJson("/api/mobile/admin/tournaments/{$t->id}/pairs/{$pair->id}")
            ->assertOk()
            ->assertJsonCount(0, 'pairs')
            ->assertJsonCount(2, 'unpaired');

        $this->assertSame(2, $t->participants()->count());
    }

    /** В одиночных турнирах пар нет — приложение не должно рисовать раздел. */
    public function test_solo_tournament_reports_no_support(): void
    {
        $t = $this->tournament(['is_paired' => false]);
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/mobile/admin/tournaments/{$t->id}/pairs")
            ->assertOk()
            ->assertJsonPath('supported', false);
    }

    public function test_stranger_cannot_add_a_pair(): void
    {
        $t = $this->tournament();
        [$a, $b] = User::factory()->count(2)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/pairs", [
            'player1_id' => $a->id, 'player2_id' => $b->id,
        ])->assertForbidden();

        $this->assertSame(0, JustPadelItPair::where('tournament_id', $t->id)->count());
    }
}
