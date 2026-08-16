<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentGroupMatch;
use App\Models\TournamentTeamGroup;
use App\Models\TournamentTeam;
use App\Models\User;
use App\Services\PlayerMatchHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Сборка истории матчей игрока.
 *
 * Достижения считают форматы по типу турнира и клубы по id, поэтому эти поля
 * обязаны быть в каждой записи. Наружу в приложение они не уходят.
 */
class PlayerMatchHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tournament, 1: array<int, User>} */
    private function teamTournamentWithMatch(): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес']);
        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'team',
            'status' => 'completed',
            'start_date' => now()->subDay(),
        ]);

        $players = [];
        for ($i = 0; $i < 4; $i++) {
            $players[] = User::factory()->create(['name' => "И{$i}"]);
        }

        $teamA = TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $players[0]->id, 'player2_id' => $players[1]->id,
            'status' => 'approved',
        ]);
        $teamB = TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $players[2]->id, 'player2_id' => $players[3]->id,
            'status' => 'approved',
        ]);

        $group = TournamentTeamGroup::create(['tournament_id' => $tournament->id, 'name' => 'Группа A']);
        TournamentGroupMatch::create([
            'group_id' => $group->id,
            'team1_id' => $teamA->id, 'team2_id' => $teamB->id,
            'team1_score' => 6, 'team2_score' => 3,
            'status' => 'completed',
        ]);

        return [$tournament, $players];
    }

    public function test_match_carries_tournament_id_type_and_club(): void
    {
        [$tournament, $players] = $this->teamTournamentWithMatch();

        $matches = app(PlayerMatchHistory::class)->for($players[0]);

        $this->assertCount(1, $matches);
        $this->assertSame($tournament->id, $matches[0]['tournament_id']);
        $this->assertSame('team', $matches[0]['tournament_type'], 'тип турнира, а не стадия матча');
        $this->assertSame($tournament->club_id, $matches[0]['club_id']);
        $this->assertSame('win', $matches[0]['result']);
        $this->assertSame($players[1]->id, $matches[0]['partner']['id']);
    }

    public function test_history_endpoint_answer_is_unchanged(): void
    {
        [, $players] = $this->teamTournamentWithMatch();
        Sanctum::actingAs($players[0]);

        $response = $this->getJson('/api/mobile/matches/history')->assertOk();

        // Набор полей в ответе тот же, что до выноса: служебные поля наружу не текут.
        $keys = array_keys($response->json('matches.0'));
        sort($keys);
        $this->assertSame(
            ['date', 'format', 'id', 'opponents', 'partner', 'result', 'score', 'tournament_name'],
            $keys
        );
    }
}
