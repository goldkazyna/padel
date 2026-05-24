<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\RatingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SuperAdminManualRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sets_rating_manually_writes_history_and_derives_level(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $player = User::factory()->create(['rating' => 4503, 'level' => 4.5]);

        $this->actingAs($admin)
            ->put(route('club.users.update', $player), [
                'name' => $player->name,
                'level' => $player->level,
                'rating' => 4509,
            ])
            ->assertRedirect();

        $fresh = $player->fresh();
        // Рейтинг выставлен напрямую
        $this->assertSame(4509, (int) $fresh->rating);
        // Уровень выведен из рейтинга: floor(4509/250)*0.25 = 18*0.25 = 4.5
        $this->assertSame(4.5, (float) $fresh->level);

        // Записана ручная корректировка
        $rh = RatingHistory::where('user_id', $player->id)
            ->whereNull('tournament_id')
            ->where('reason', 'Ручная корректировка')
            ->latest('id')
            ->first();
        $this->assertNotNull($rh);
        $this->assertSame(4503, (int) $rh->rating_before);
        $this->assertSame(4509, (int) $rh->rating_after);
        $this->assertSame(6, (int) $rh->change);
    }

    public function test_rating_takes_precedence_over_level_field(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $player = User::factory()->create(['rating' => 2000, 'level' => 2.0]);

        // Передаём и level, и rating — выигрывает rating
        $this->actingAs($admin)
            ->put(route('club.users.update', $player), [
                'name' => $player->name,
                'level' => 3.0,
                'rating' => 5000,
            ])
            ->assertRedirect();

        $fresh = $player->fresh();
        $this->assertSame(5000, (int) $fresh->rating);
        $this->assertSame(5.0, (float) $fresh->level); // floor(5000/250)*0.25 = 5.0
    }

    public function test_non_super_admin_cannot_set_rating_directly(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $clubAdmin = User::factory()->create(['role' => 'club_admin']);
        $clubAdmin->adminClubs()->attach($club->id);
        $player = User::factory()->create(['rating' => 2000, 'level' => 2.0]);

        $this->actingAs($clubAdmin)
            ->put(route('club.users.update', $player), [
                'name' => $player->name,
                'level' => 2.0,
                'rating' => 9000, // должно игнорироваться для не-супер-админа
            ])
            ->assertRedirect();

        $fresh = $player->fresh();
        // Рейтинг не стал 9000 — остался производным от уровня
        $this->assertNotSame(9000, (int) $fresh->rating);
        $this->assertSame((int) (2.0 * 1000 + 125), (int) $fresh->rating);
    }
}
