<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutoVerificationGateTest extends TestCase
{
    use RefreshDatabase;

    /** Игрок с аватаром + завершённый турнир, где он участник. */
    private function playerInCompletedTournament(Club $club): User
    {
        $user = User::factory()->create([
            'avatar' => 'https://example.com/a.jpg',
            'level_verified' => false,
        ]);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => 'completed', 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
        $t->participants()->attach($user->id, ['status' => 'approved']);
        return $user;
    }

    private function clubWithUsers(): Club
    {
        return Club::create(['name' => 'C1', 'address' => 'A', 'features' => ['users' => true]]);
    }

    private function clubWithoutUsers(): Club
    {
        return Club::create(['name' => 'C2', 'address' => 'A', 'features' => ['users' => false]]);
    }

    public function test_tournament_finish_verifies_when_club_has_users_feature(): void
    {
        $club = $this->clubWithUsers();
        $user = $this->playerInCompletedTournament($club);
        $t = $user->tournaments()->first();

        $t->recalculateParticipantsVerification(null, $club->id);

        $this->assertTrue((bool) $user->fresh()->level_verified);
    }

    public function test_tournament_finish_does_not_verify_when_users_feature_off(): void
    {
        $club = $this->clubWithoutUsers();
        $user = $this->playerInCompletedTournament($club);
        $t = $user->tournaments()->first();

        $t->recalculateParticipantsVerification(null, $club->id);

        $this->assertFalse((bool) $user->fresh()->level_verified);
    }

    public function test_avatar_upload_verifies_when_club_has_users_feature(): void
    {
        Storage::fake('public');
        $club = $this->clubWithUsers();
        $user = User::factory()->create(['avatar' => null, 'level_verified' => false]);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => 'completed', 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
        $t->participants()->attach($user->id, ['status' => 'approved']);

        Sanctum::actingAs($user);
        $this->postJson('/api/mobile/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('a.jpg'),
        ])->assertOk();

        $this->assertTrue((bool) $user->fresh()->level_verified);
    }

    public function test_avatar_upload_does_not_verify_when_users_feature_off(): void
    {
        Storage::fake('public');
        $club = $this->clubWithoutUsers();
        $user = User::factory()->create(['avatar' => null, 'level_verified' => false]);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'T', 'type' => 'americano',
            'status' => 'completed', 'start_date' => now()->subDay(),
            'min_level' => 1, 'max_level' => 5, 'max_participants' => 8,
        ]);
        $t->participants()->attach($user->id, ['status' => 'approved']);

        Sanctum::actingAs($user);
        $this->postJson('/api/mobile/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('a.jpg'),
        ])->assertOk();

        $this->assertFalse((bool) $user->fresh()->level_verified);
    }
}
