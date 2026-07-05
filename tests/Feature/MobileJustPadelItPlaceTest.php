<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\JustPadelItPlayer;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileJustPadelItPlaceTest extends TestCase
{
    use RefreshDatabase;

    /** Создаёт завершённый solo JPI турнир с $count игроками разной результативности. */
    private function scenario(int $count = 4): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $t = Tournament::create([
            'club_id' => $club->id,
            'name' => 'JPI',
            'type' => 'just_padel_it',
            'is_paired' => false,
            'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5.75,
            'max_participants' => $count,
            'status' => 'completed',
            'is_rated' => true,
        ]);

        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $u = User::factory()->create(['rating' => 1000 + $i * 50]);
            TournamentParticipant::create([
                'tournament_id' => $t->id,
                'user_id' => $u->id,
                'status' => 'registered',
            ]);

            // Убывающие total_points: первый игрок — лидер, последний — аутсайдер.
            $points = ($count - $i) * 10;
            JustPadelItPlayer::create([
                'tournament_id' => $t->id,
                'user_id' => $u->id,
                'total_points' => $points,
                'wins' => $count - $i,
                'losses' => $i,
                'points_for' => $points,
                'points_against' => 5,
            ]);

            RatingHistory::create([
                'user_id' => $u->id,
                'tournament_id' => $t->id,
                'rating_before' => 1000,
                'rating_after' => 1000 + $i,
                'change' => $i,
                'reason' => 'tournament',
            ]);

            $users[] = $u;
        }

        return [$t, $users];
    }

    /** Достаёт my_result турнира $tournamentId из ответа /tournaments/archive. */
    private function myResultFromArchive(int $tournamentId): ?array
    {
        $response = $this->getJson('/api/mobile/tournaments/archive')->assertOk();
        $tournaments = $response->json('tournaments');
        foreach ($tournaments as $item) {
            if ((int) $item['id'] === $tournamentId) {
                return $item['my_result'];
            }
        }
        $this->fail("Турнир {$tournamentId} не найден в архиве");
    }

    public function test_leader_gets_place_one_in_archive(): void
    {
        [$t, $users] = $this->scenario(4);

        Sanctum::actingAs($users[0]);
        $myResult = $this->myResultFromArchive($t->id);

        $this->assertNotNull($myResult, 'my_result не должен быть null для завершённого JPI турнира');
        $this->assertSame(1, $myResult['place']);
    }

    public function test_outsider_gets_place_greater_than_one_in_archive(): void
    {
        [$t, $users] = $this->scenario(4);

        Sanctum::actingAs($users[3]);
        $myResult = $this->myResultFromArchive($t->id);

        $this->assertNotNull($myResult);
        $this->assertGreaterThan(1, $myResult['place']);
        $this->assertSame(4, $myResult['place']);
    }
}
