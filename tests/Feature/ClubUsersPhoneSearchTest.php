<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubUsersPhoneSearchTest extends TestCase
{
    use RefreshDatabase;

    private function target(): User
    {
        return User::factory()->create([
            'first_name' => 'Тестовый', 'last_name' => 'Игрок', 'name' => 'Тестовый Игрок',
            'phone' => '74441111199', 'role' => 'player', 'city' => null,
        ]);
    }

    public function test_super_admin_can_search_by_phone_and_sees_it(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $t = $this->target();

        // Поиск по фрагменту телефона, которого нет в имени/ID.
        $resp = $this->actingAs($super)->get('/club/users?search=4441111199');
        $resp->assertOk();
        $resp->assertSee('Тестовый Игрок');   // найден по телефону
        $resp->assertSee('74441111199');       // телефон показан
    }

    public function test_club_admin_cannot_search_by_phone_and_phone_hidden(): void
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']); // city null → без фильтра города
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = $this->target();

        // По телефону клубный админ НЕ находит.
        $byPhone = $this->actingAs($admin)->get('/club/users?search=4441111199');
        $byPhone->assertOk();
        $byPhone->assertDontSee('Тестовый Игрок');

        // По имени находит, но телефон не показан.
        $byName = $this->actingAs($admin)->get('/club/users?search=Тестовый');
        $byName->assertOk();
        $byName->assertSee('Тестовый Игрок');
        $byName->assertDontSee('74441111199');
    }
}
