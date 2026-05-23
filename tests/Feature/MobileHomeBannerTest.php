<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MobileHomeBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_returns_banner_when_image_set(): void
    {
        Banner::create([
            'image_path' => '/banners/promo.jpg',
            'link' => 'https://example.com/promo',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('banner.image', url('/banners/promo.jpg'))
            ->assertJsonPath('banner.link', 'https://example.com/promo');
    }

    public function test_home_banner_null_when_no_image(): void
    {
        Banner::create(['link' => 'https://example.com/promo']);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('banner', null);
    }

    public function test_home_banner_null_when_no_banner_row(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/mobile/home')
            ->assertOk()
            ->assertJsonPath('banner', null);
    }
}
