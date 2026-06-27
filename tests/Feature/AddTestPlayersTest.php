<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddTestPlayersTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_numbered_test_accounts_1_to_16_are_added(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        // Тестовые аккаунты 1..16@gmail.com
        for ($i = 1; $i <= 16; $i++) {
            User::factory()->create(['role' => 'player', 'email' => "{$i}@gmail.com"]);
        }
        // Реальный игрок с телефонным email — НЕ должен попасть.
        $real = User::factory()->create(['role' => 'player', 'email' => '7801107@gmail.com']);

        $t = Tournament::factory()->create([
            'club_id' => $club->id, 'type' => 'king_of_court', 'status' => 'open',
            'max_participants' => 8,
        ]);

        $this->actingAs($admin)
            ->post(route('club.tournaments.addTestPlayers', $t))
            ->assertRedirect();

        $ids = $t->participants()->pluck('users.id')->all();
        $this->assertCount(8, $ids);
        $this->assertNotContains($real->id, $ids, 'реальный игрок с телефонным email не должен добавляться');

        // Все добавленные — из набора 1..16@gmail.com
        $addedEmails = User::whereIn('id', $ids)->pluck('email')->all();
        foreach ($addedEmails as $email) {
            $this->assertMatchesRegularExpression('/^(1[0-6]|[1-9])@gmail\.com$/', $email);
        }
    }
}
