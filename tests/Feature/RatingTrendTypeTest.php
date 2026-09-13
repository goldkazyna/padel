<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use App\Support\RatingTrend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Точка динамики знает тип турнира.
 *
 * Приложение открывает точку тем же экраном, что и сам турнир: у Мексикано,
 * Короля корта и Флекса результаты выглядят по-разному.
 */
class RatingTrendTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_в_точке_есть_тип_турнира(): void
    {
        $club = Club::create(['name' => 'Padel Sai', 'address' => 'А', 'city' => 'Алматы']);
        $user = User::factory()->create(['rating' => 2100]);

        $tournament = Tournament::factory()->create([
            'club_id' => $club->id,
            'type' => 'king_of_court',
            'status' => 'completed',
            'name' => 'Король корта, среда',
        ]);

        DB::table('rating_history')->insert([
            'user_id' => $user->id,
            'tournament_id' => $tournament->id,
            'club_id' => $club->id,
            'rating_before' => 2050,
            'rating_after' => 2100,
            'change' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $points = RatingTrend::points($user);

        $this->assertNotEmpty($points);
        $last = end($points);
        $this->assertSame($tournament->id, $last['tournament_id']);
        $this->assertSame('king_of_court', $last['type']);
    }

    public function test_у_ручной_правки_типа_нет(): void
    {
        $user = User::factory()->create(['rating' => 2000]);

        DB::table('rating_history')->insert([
            'user_id' => $user->id,
            'tournament_id' => null,
            'rating_before' => 1950,
            'rating_after' => 2000,
            'change' => 50,
            'reason' => 'Корректировка администратором',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $points = RatingTrend::points($user);
        $last = end($points);

        $this->assertNull($last['tournament_id']);
        $this->assertArrayNotHasKey('type', $last);
    }
}
