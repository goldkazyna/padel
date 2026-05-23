<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class TournamentClubTelegramTest extends TestCase
{
    use RefreshDatabase;

    public function test_tournament_payload_includes_club_telegram_url(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'telegram_url' => 'https://t.me/orgchan']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addDays(2), 'registration_deadline' => now()->addDay(),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/tournaments/{$t->id}")
            ->assertOk()
            ->assertJsonPath('tournament.club.telegram_url', 'https://t.me/orgchan');
    }

    public function test_tournament_payload_club_telegram_url_is_null_when_not_set(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 8,
            'start_date' => now()->addDays(2), 'registration_deadline' => now()->addDay(),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/tournaments/{$t->id}")
            ->assertOk()
            ->assertJsonPath('tournament.club.telegram_url', null);
    }
}
