<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClubShareLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_club_share_landing_renders_with_deep_link(): void
    {
        $club = Club::create(['name' => 'My Club', 'address' => 'A', 'city' => 'Алматы']);
        $resp = $this->get("/c/{$club->id}");
        $resp->assertOk();
        $resp->assertSee('My Club');
        $resp->assertSee("padelp://club/{$club->id}", false);
    }
}
