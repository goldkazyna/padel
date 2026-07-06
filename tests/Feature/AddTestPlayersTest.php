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

    public function test_only_numbered_test_accounts_1_to_32_are_added(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        // Тестовые аккаунты 1..20@gmail.com (в т.ч. 17 — проверяем расширение >16).
        for ($i = 1; $i <= 20; $i++) {
            User::factory()->create(['role' => 'player', 'email' => "{$i}@gmail.com"]);
        }
        // Реальный игрок с телефонным email — НЕ должен попасть.
        $real = User::factory()->create(['role' => 'player', 'email' => '7801107@gmail.com']);
        $seventeen = User::where('email', '17@gmail.com')->firstOrFail();

        $t = Tournament::factory()->create([
            'club_id' => $club->id, 'type' => 'king_of_court', 'status' => 'open',
            'max_participants' => 18,
        ]);

        $this->actingAs($admin)
            ->post(route('club.tournaments.addTestPlayers', $t))
            ->assertRedirect();

        $ids = $t->participants()->pluck('users.id')->all();
        $this->assertCount(18, $ids);
        $this->assertNotContains($real->id, $ids, 'реальный игрок с телефонным email не должен добавляться');
        $this->assertContains($seventeen->id, $ids, 'аккаунт 17@gmail.com должен добавляться (диапазон расширен до 32)');

        // Все добавленные — из набора 1..32@gmail.com (число ≤ 32).
        $addedEmails = User::whereIn('id', $ids)->pluck('email')->all();
        foreach ($addedEmails as $email) {
            $this->assertMatchesRegularExpression('/^(3[0-2]|[12][0-9]|[1-9])@gmail\.com$/', $email);
        }
    }
}
