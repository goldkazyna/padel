<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\JustPadelItPlayer;
use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileRatingJustPadelItPlaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_just_padel_it_place_computed_from_leaderboard(): void
    {
        $tournament = Tournament::factory()->create([
            'type' => 'just_padel_it',
            'status' => 'completed',
            'is_paired' => false,
        ]);

        $topUser = User::factory()->create(['rating' => 1500]);
        $secondUser = User::factory()->create(['rating' => 1400]);

        JustPadelItPlayer::create([
            'tournament_id' => $tournament->id,
            'user_id' => $topUser->id,
            'total_points' => 30,
            'wins' => 4,
        ]);
        JustPadelItPlayer::create([
            'tournament_id' => $tournament->id,
            'user_id' => $secondUser->id,
            'total_points' => 20,
            'wins' => 2,
        ]);

        // Рейтинговая история — путь построения `history` в player(), не требует
        // is_rated=false + completed + участие через whereHas (у just_padel_it
        // такой relation в non-rated ветке вообще не проверяется).
        RatingHistory::create([
            'user_id' => $topUser->id,
            'tournament_id' => $tournament->id,
            'rating_before' => 1470,
            'rating_after' => 1500,
            'change' => 30,
            'reason' => 'Турнир',
        ]);
        RatingHistory::create([
            'user_id' => $secondUser->id,
            'tournament_id' => $tournament->id,
            'rating_before' => 1420,
            'rating_after' => 1400,
            'change' => -20,
            'reason' => 'Турнир',
        ]);

        $authUser = User::factory()->create();
        Sanctum::actingAs($authUser);

        $response = $this->getJson("/api/mobile/rating/player/{$topUser->id}")
            ->assertOk();

        $history = $response->json('history');
        $entry = collect($history)->firstWhere('tournament_id', $tournament->id);

        $this->assertNotNull($entry, 'JPI tournament должен быть в истории профиля');
        $this->assertSame(1, $entry['place'], 'Игрок с наибольшим total_points должен быть на 1 месте');

        // Второй игрок — не должен иметь место 1
        $response2 = $this->getJson("/api/mobile/rating/player/{$secondUser->id}")
            ->assertOk();
        $history2 = $response2->json('history');
        $entry2 = collect($history2)->firstWhere('tournament_id', $tournament->id);

        $this->assertNotNull($entry2);
        $this->assertNotSame(1, $entry2['place'], 'Игрок с меньшим total_points не должен быть на 1 месте');
    }
}
