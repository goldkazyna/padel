<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AdminBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_and_save_banner(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->get('/admin/banners')->assertOk();

        $this->actingAs($admin)->post('/admin/banners', [
            'link' => 'https://example.com/promo',
        ])->assertRedirect();

        $this->assertSame('https://example.com/promo', Banner::first()->link);
    }

    public function test_non_super_admin_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'player']);
        $this->actingAs($user)->get('/admin/banners')->assertForbidden();
    }
}
