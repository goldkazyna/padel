<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileAdminJustPadelItCreateTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdminWithClub(): Club
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        Sanctum::actingAs($admin);
        return $club;
    }

    public function test_admin_creates_solo_just_padel_it_via_mobile(): void
    {
        $club = $this->actingAdminWithClub();

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'type' => 'just_padel_it',
            'name' => 'JPI турнир',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1.0,
            'max_level' => 5.0,
            'max_participants' => 12,
            'status' => 'open',
            'courts_count' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $t = Tournament::where('type', 'just_padel_it')->first();
        $this->assertNotNull($t);
        $this->assertFalse((bool) $t->is_paired);
    }

    public function test_admin_creates_paired_just_padel_it_via_mobile(): void
    {
        $club = $this->actingAdminWithClub();

        $this->postJson("/api/mobile/admin/clubs/{$club->id}/tournaments", [
            'type' => 'just_padel_it',
            'name' => 'JPI пары',
            'start_date' => now()->addDay()->toIso8601String(),
            'min_level' => 1.0,
            'max_level' => 5.0,
            'max_participants' => 12,
            'status' => 'open',
            'courts_count' => 3,
            'is_paired' => true,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $t = Tournament::where('type', 'just_padel_it')->first();
        $this->assertNotNull($t);
        $this->assertTrue((bool) $t->is_paired);
    }
}
