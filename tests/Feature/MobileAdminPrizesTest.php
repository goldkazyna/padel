<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Призовой турнир: настройка задаётся при создании, значит и меняться
 * должна там же, где остальные — в редактировании.
 */
class MobileAdminPrizesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Tournament,1:User} */
    private function makeTournament(array $attrs = []): array
    {
        $club = Club::create(['name' => 'Клуб', 'address' => 'Адрес', 'city' => 'Алматы']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);

        $tournament = Tournament::factory()->create(array_merge([
            'club_id' => $club->id,
            'type' => 'americano',
            'status' => 'open',
            'max_participants' => 8,
            'start_date' => now()->addDay(),
        ], $attrs));

        return [$tournament, $admin];
    }

    /** Тело запроса на обновление: обязательные поля плюс переданные. */
    private function payload(Tournament $t, array $extra = []): array
    {
        return array_merge([
            'name' => $t->name,
            // Дату не меняем: её правка рассылает участникам пуши.
            'start_date' => $t->start_date->format('Y-m-d H:i'),
            'min_level' => 1,
            'max_level' => 5.75,
            'max_participants' => 8,
            'status' => 'open',
        ], $extra);
    }

    public function test_detail_returns_prizes(): void
    {
        [$t, $admin] = $this->makeTournament([
            'has_prizes' => true,
            'prizes' => '1 место — ракетка',
        ]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/mobile/admin/tournaments/{$t->id}")
            ->assertOk()
            ->assertJsonPath('tournament.has_prizes', true)
            ->assertJsonPath('tournament.prizes', '1 место — ракетка');
    }

    public function test_prizes_can_be_turned_on_in_edit(): void
    {
        [$t, $admin] = $this->makeTournament(['has_prizes' => false]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", $this->payload($t, [
            'has_prizes' => true,
            'prizes' => '1 место — ракетка, 2 место — сумка',
        ]))->assertOk();

        $t->refresh();
        $this->assertTrue((bool) $t->has_prizes);
        $this->assertSame('1 место — ракетка, 2 место — сумка', $t->prizes);
    }

    public function test_prizes_can_be_turned_off_in_edit(): void
    {
        [$t, $admin] = $this->makeTournament([
            'has_prizes' => true,
            'prizes' => 'Кубок',
        ]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", $this->payload($t, [
            'has_prizes' => false,
        ]))->assertOk();

        $t->refresh();
        $this->assertFalse((bool) $t->has_prizes);
        // Текст призов убираем вместе с флагом — иначе он всплывёт при
        // повторном включении и покажет неактуальное.
        $this->assertNull($t->prizes);
    }

    public function test_prizes_untouched_when_not_sent(): void
    {
        // Старые сборки приложения поля не шлют — настройка не должна слетать.
        [$t, $admin] = $this->makeTournament([
            'has_prizes' => true,
            'prizes' => 'Кубок',
        ]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", $this->payload($t))
            ->assertOk();

        $t->refresh();
        $this->assertTrue((bool) $t->has_prizes);
        $this->assertSame('Кубок', $t->prizes);
    }
}
