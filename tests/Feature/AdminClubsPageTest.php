<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Список клубов в супер-админке.
 *
 * Карточка на клуб, две вкладки и администраторы с почтой прямо в списке —
 * раньше за одним адресом приходилось идти на отдельный экран.
 */
class AdminClubsPageTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_admins_and_their_emails_are_visible_in_the_list(): void
    {
        $club = Club::create(['name' => 'DAVAY PADEL', 'address' => 'Алматы']);
        $admin = User::factory()->create([
            'name' => 'Денис Дудников',
            'email' => 'denis@davaypadel.kz',
            'role' => 'club_admin',
        ]);
        $admin->adminClubs()->attach($club->id);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.clubs.index'))
            ->assertOk()
            ->assertSee('DAVAY PADEL')
            ->assertSee('Денис Дудников')
            ->assertSee('denis@davaypadel.kz');
    }

    public function test_clubs_and_communities_are_split_into_tabs(): void
    {
        Club::create(['name' => 'Обычный клуб', 'address' => 'А', 'is_community' => false]);
        Club::create(['name' => 'Наше комьюнити', 'address' => 'Б', 'is_community' => true]);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.clubs.index'))
            ->assertOk();

        // Обе вкладки со счётчиками, обе группы на странице.
        $response->assertSee('data-tab="clubs"', false);
        $response->assertSee('data-tab="communities"', false);
        $response->assertSee('Обычный клуб');
        $response->assertSee('Наше комьюнити');
    }

    public function test_club_without_admins_says_so(): void
    {
        Club::create(['name' => 'Пустой клуб', 'address' => 'В']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.clubs.index'))
            ->assertOk()
            ->assertSee('Пока никого не назначили');
    }

    public function test_actions_are_kept(): void
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Г']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.clubs.index'))
            ->assertOk()
            ->assertSee(route('admin.clubs.admins', $club), false)
            ->assertSee(route('admin.clubs.edit', $club), false)
            ->assertSee(route('admin.clubs.create'), false);
    }

    /** Логотип хранится в трёх форматах — ссылка должна собираться из любого. */
    public function test_logo_url_handles_every_stored_format(): void
    {
        $this->assertNull((new Club(['logo' => null]))->logo_url);
        $this->assertSame(
            'https://cdn.example.com/a.png',
            (new Club(['logo' => 'https://cdn.example.com/a.png']))->logo_url
        );
        $this->assertSame(url('/logos/x.jpg'), (new Club(['logo' => '/logos/x.jpg']))->logo_url);
        $this->assertSame(asset('logos/old.png'), (new Club(['logo' => 'old.png']))->logo_url);
    }
}
