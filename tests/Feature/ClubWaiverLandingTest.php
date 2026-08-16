<?php

namespace Tests\Feature;

use App\Models\Club;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Страница, куда ведёт QR со стойки клуба.
 */
class ClubWaiverLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_pushes_into_the_app(): void
    {
        $club = Club::create([
            'name' => 'Клуб', 'address' => 'А',
            'waiver_enabled' => true, 'waiver_text' => 'Текст',
        ]);

        $this->get('/w/' . $club->id)
            ->assertOk()
            ->assertSee('padelp://waiver/' . $club->id, false)
            ->assertSee('отсканируйте код ещё раз')
            ->assertSee('Клуб');
    }

    public function test_page_says_so_when_the_club_does_not_collect(): void
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'А']);

        $this->get('/w/' . $club->id)
            ->assertOk()
            ->assertSee('не собирает')
            ->assertDontSee('padelp://waiver/', false);
    }

    public function test_unknown_club_gives_404(): void
    {
        $this->get('/w/999999')->assertNotFound();
    }
}
