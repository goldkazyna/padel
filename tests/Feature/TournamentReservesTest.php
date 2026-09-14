<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Забронированные места живут одинаково во всех путях.
 *
 * Раньше в приложении резерв сажался только при создании: при редактировании
 * число менялось молча, а мест не прибавлялось. Веб при этом умел и то, и
 * другое — разница вылезла у организатора, который поправил турнир и не
 * увидел брони.
 */
class TournamentReservesTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create(['name' => 'Davay Padel', 'address' => 'А', 'city' => 'Алматы']);
        $this->admin = User::factory()->create(['role' => 'club_admin']);
        $this->admin->adminClubs()->attach($this->club->id);

        // Служебные аккаунты, которыми занимают места.
        for ($i = 1; $i <= 6; $i++) {
            User::factory()->create(['role' => 'reserve', 'name' => "Резерв {$i}"]);
        }
    }

    private function tournament(int $reserve = 0): Tournament
    {
        return Tournament::factory()->create([
            'club_id' => $this->club->id,
            'type' => 'americano',
            'status' => 'open',
            'max_participants' => 12,
            'reserve_count' => $reserve,
        ]);
    }

    private function reservesOf(Tournament $t): int
    {
        return $t->participants()->where('users.role', 'reserve')->count();
    }

    public function test_приложение_сажает_резерв_при_редактировании(): void
    {
        $t = $this->tournament();
        $this->assertSame(0, $this->reservesOf($t));

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", [
            'name' => $t->name,
            'type' => 'americano',
            'start_date' => now('Asia/Almaty')->addWeek()->format('Y-m-d H:i:s'),
            'max_participants' => 12,
            'min_level' => 1,
            'max_level' => 4,
            'reserve_count' => 2,
        ])->assertOk();

        $this->assertSame(2, $this->reservesOf($t->fresh()), 'два места должны занять резервисты');
        $this->assertSame(2, (int) $t->fresh()->reserve_count);
    }

    public function test_уменьшение_снимает_лишних(): void
    {
        $t = $this->tournament();

        Sanctum::actingAs($this->admin);
        $payload = [
            'name' => $t->name,
            'type' => 'americano',
            'start_date' => now('Asia/Almaty')->addWeek()->format('Y-m-d H:i:s'),
            'max_participants' => 12,
            'min_level' => 1,
            'max_level' => 4,
        ];

        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", $payload + ['reserve_count' => 3])->assertOk();
        $this->assertSame(3, $this->reservesOf($t->fresh()));

        $this->putJson("/api/mobile/admin/tournaments/{$t->id}", $payload + ['reserve_count' => 1])->assertOk();
        $this->assertSame(1, $this->reservesOf($t->fresh()));
    }

    public function test_после_старта_состав_не_трогаем(): void
    {
        $t = $this->tournament();
        $t->update(['status' => 'in_progress']);

        Sanctum::actingAs($this->admin);
        $response = $this->putJson("/api/mobile/admin/tournaments/{$t->id}", [
            'name' => $t->name,
            'type' => 'americano',
            'start_date' => now('Asia/Almaty')->addWeek()->format('Y-m-d H:i:s'),
            'max_participants' => 12,
            'min_level' => 1,
            'max_level' => 4,
            'reserve_count' => 2,
        ]);

        // Идущий турнир вообще правят ограниченно; главное — состав не тронут:
        // после посева игроки разложены по группам, досадить туда нельзя.
        $this->assertSame(0, $this->reservesOf($t->fresh()),
            'после старта досаживать нельзя, статус ответа: ' . $response->status());
    }

    public function test_веб_сажает_резерв_при_редактировании(): void
    {
        $t = $this->tournament();

        $this->actingAs($this->admin)->put("/club/tournaments/{$t->id}", [
            'club_id' => $this->club->id,
            'name' => $t->name,
            'type' => 'americano',
            'status' => 'open',
            'start_date' => now('Asia/Almaty')->addWeek()->format('Y-m-d H:i:s'),
            'max_participants' => 12,
            'price' => 10000,
            'min_level' => 1,
            'max_level' => 4,
            'courts_count' => 2,
            'reserve_count' => 2,
        ])->assertRedirect();

        $this->assertSame(2, $this->reservesOf($t->fresh()));
    }

    public function test_приложение_сажает_резерв_при_создании(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/mobile/admin/clubs/{$this->club->id}/tournaments", [
            'name' => 'Вечер пятницы',
            'type' => 'americano',
            'status' => 'open',
            'start_date' => now('Asia/Almaty')->addWeek()->format('Y-m-d H:i:s'),
            'max_participants' => 12,
            'price' => 10000,
            'min_level' => 1,
            'max_level' => 4,
            'courts_count' => 2,
            'reserve_count' => 3,
        ])->assertOk();

        $created = Tournament::where('name', 'Вечер пятницы')->firstOrFail();
        $this->assertSame(3, $this->reservesOf($created));
    }
}
