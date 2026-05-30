<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ClubComingSoonTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_store_saves_coming_soon(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->post(route('admin.clubs.store'), [
                'name' => 'Новый клуб',
                'address' => 'Адрес 1',
                'coming_soon' => '1',
            ])->assertRedirect();

        $club = Club::where('name', 'Новый клуб')->first();
        $this->assertNotNull($club);
        $this->assertTrue($club->coming_soon);
    }

    public function test_admin_update_toggles_coming_soon_off(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $club = Club::create(['name' => 'C', 'address' => 'A', 'coming_soon' => true]);

        $this->actingAs($admin)
            ->put(route('admin.clubs.update', $club), [
                'name' => 'C', 'address' => 'A',
                // coming_soon не отправлен → hidden 0 → выключен
            ])->assertRedirect();

        $this->assertFalse($club->fresh()->coming_soon);
    }

    public function test_mobile_show_returns_coming_soon(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'coming_soon' => true]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/clubs/{$club->id}")
            ->assertOk()
            ->assertJsonPath('club.coming_soon', true);
    }
}
