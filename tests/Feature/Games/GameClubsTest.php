<?php

namespace Tests\Feature\Games;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameClubsTest extends TestCase
{
    use RefreshDatabase;

    public function test_clubs_returns_active_clubs(): void
    {
        $active = Club::factory()->create(['is_active' => true]);
        Club::factory()->create(['is_active' => false]);

        Sanctum::actingAs(User::factory()->create());

        $res = $this->getJson('/api/mobile/games/clubs');
        $res->assertOk()->assertJson(['success' => true]);
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_clubs_excludes_test_clubs(): void
    {
        Club::factory()->create(['is_active' => true, 'is_test' => true]);
        Sanctum::actingAs(User::factory()->create());
        $res = $this->getJson('/api/mobile/games/clubs')->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_clubs_requires_auth(): void
    {
        $this->getJson('/api/mobile/games/clubs')->assertUnauthorized();
    }
}
