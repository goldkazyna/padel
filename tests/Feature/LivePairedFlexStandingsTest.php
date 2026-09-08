<?php

namespace Tests\Feature;

use App\Models\AmericanoFlexPlayer;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Живая таблица парного флекса отдаёт обоих игроков пары отдельно.
 *
 * Склеенное «Тест #3 / Тестовый1 Тестовый1» приложение разбивало по первому
 * пробелу (имя на одной строке, фамилия на другой) — для пары выходила каша
 * из половинок. Поэтому строка обязана нести список players.
 */
class LivePairedFlexStandingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_строка_пары_несёт_обоих_игроков(): void
    {
        $club = Club::create(['name' => 'Padel Hills', 'address' => 'А', 'city' => 'Алматы']);
        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'americano_flex',
            'is_paired' => true,
            'status' => 'in_progress',
            'max_participants' => 8,
        ]);

        $first = User::factory()->create(['name' => 'Денис Дудников', 'level_verified' => true]);
        $second = User::factory()->create(['name' => 'Марина Дудникова']);

        foreach ([$first, $second] as $user) {
            $tournament->participants()->attach($user->id, ['status' => 'registered']);
            AmericanoFlexPlayer::create([
                'tournament_id' => $tournament->id,
                'user_id' => $user->id,
                'rating_before' => $user->rating,
                'total_points' => 0,
                'matches_played' => 0,
                'bye_count' => 0,
                'bye_streak' => 0,
            ]);
        }

        TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $first->id,
            'player2_id' => $second->id,
            'status' => 'approved',
            'rating_avg' => 2000,
        ]);

        Sanctum::actingAs($first);

        $response = $this->getJson("/api/mobile/tournaments/{$tournament->id}/live");

        $response->assertOk()
            ->assertJsonPath('groups.0.leaderboard.0.players.0.name', 'Денис Дудников')
            ->assertJsonPath('groups.0.leaderboard.0.players.1.name', 'Марина Дудникова')
            // Галочка верификации у каждого своя: она рисуется рядом с именем.
            ->assertJsonPath('groups.0.leaderboard.0.players.0.verified', true)
            ->assertJsonPath('groups.0.leaderboard.0.players.1.verified', false);

        // Склейка остаётся для тех, кто ещё не умеет читать players.
        $this->assertSame(
            'Денис Дудников / Марина Дудникова',
            $response->json('groups.0.leaderboard.0.name')
        );
    }
}
