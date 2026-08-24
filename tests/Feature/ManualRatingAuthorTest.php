<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\RatingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ручная правка рейтинга подписывается клубом, чей администратор её сделал.
 * Раньше в «Динамике рейтинга» там стояло безличное «Padel Kz», и игрок не
 * мог понять, кто и почему поменял ему рейтинг.
 */
class ManualRatingAuthorTest extends TestCase
{
    use RefreshDatabase;

    private function dynamics(User $player): array
    {
        $response = $this->actingAs($player, 'sanctum')->getJson('/api/mobile/profile');
        $response->assertOk();

        return $response->json('statistics.rating_trend_details') ?? [];
    }

    public function test_правка_из_веб_crm_подписана_клубом(): void
    {
        $club = Club::create(['name' => 'Ace Padel Club Гапеева', 'address' => 'А', 'city' => 'Караганда']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $player = User::factory()->create(['role' => 'player', 'rating' => 1589, 'level' => 1.75]);

        $this->actingAs($admin)
            ->put(route('club.users.update', $player), [
                'name' => $player->name,
                'rating' => 1875,
                'level' => 1.75,
            ]);

        $entry = RatingHistory::where('user_id', $player->id)->first();
        $this->assertNotNull($entry, 'правка должна попасть в историю рейтинга');
        $this->assertSame($club->id, $entry->club_id);
        $this->assertSame($admin->id, $entry->changed_by_user_id);
    }

    public function test_в_профиле_видно_клуб_а_не_padel_kz(): void
    {
        $club = Club::create(['name' => 'Ace Padel Club Гапеева', 'address' => 'А', 'city' => 'Караганда']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $player = User::factory()->create(['role' => 'player', 'rating' => 1875]);

        RatingHistory::create([
            'user_id' => $player->id, 'tournament_id' => null,
            'changed_by_user_id' => $admin->id, 'club_id' => $club->id,
            'rating_before' => 1589, 'rating_after' => 1875, 'change' => 286,
            'reason' => RatingHistory::REASON_MANUAL,
        ]);

        $manual = collect($this->dynamics($player))->firstWhere('is_manual', true);

        $this->assertNotNull($manual, 'ручная правка должна быть точкой на графике');
        $this->assertSame('Ace Padel Club Гапеева', $manual['club_name']);
    }

    public function test_у_старых_правок_подпись_прежняя(): void
    {
        // Записи, сделанные до появления поля, клуба не знают — там остаётся
        // прежняя подпись, а не пустая строка.
        $player = User::factory()->create(['role' => 'player', 'rating' => 1700]);
        RatingHistory::create([
            'user_id' => $player->id, 'tournament_id' => null,
            'rating_before' => 1600, 'rating_after' => 1700, 'change' => 100,
            'reason' => RatingHistory::REASON_MANUAL,
        ]);

        $manual = collect($this->dynamics($player))->firstWhere('is_manual', true);

        $this->assertSame('Padel Kz', $manual['club_name']);
    }
}
