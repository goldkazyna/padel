<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class CourtClubOnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_exposes_online_payment_enabled(): void
    {
        $club = Club::create([
            'name' => 'C', 'address' => 'A', 'is_active' => true,
            'online_payment_enabled' => true,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/courts/clubs/{$club->id}/schedule")
            ->assertOk()
            ->assertJsonPath('club.online_payment_enabled', true);
    }

    public function test_schedule_online_payment_disabled_by_default(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A', 'is_active' => true]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/mobile/courts/clubs/{$club->id}/schedule")
            ->assertOk()
            ->assertJsonPath('club.online_payment_enabled', false);
    }
}
