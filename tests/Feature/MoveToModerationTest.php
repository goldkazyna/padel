<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MoveToModerationTest extends TestCase
{
    use RefreshDatabase;

    private function mk(int $max = 2): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => $max, 'waitlist_size' => 4,
            'moderation_minutes' => 30,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        return [$admin, $t];
    }

    public function test_free_slot_just_adds_to_moderation(): void
    {
        [$admin, $t] = $this->mk(4); // свободные места есть
        $w = User::factory()->create();
        $t->participants()->attach($w->id, ['status' => 'waiting']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$w->id}/to-moderation")
            ->assertOk();

        $row = $t->participants()->where('user_id', $w->id)->first();
        $this->assertSame('pending', $row->pivot->status);
        $this->assertNotNull($row->pivot->moderation_deadline);
    }

    public function test_full_requires_choice(): void
    {
        [$admin, $t] = $this->mk(2);
        $r1 = User::factory()->create();
        $r2 = User::factory()->create();
        $t->participants()->attach($r1->id, ['status' => 'registered']);
        $t->participants()->attach($r2->id, ['status' => 'registered']);
        $w = User::factory()->create();
        $t->participants()->attach($w->id, ['status' => 'waiting']);
        Sanctum::actingAs($admin);

        // без demote_user_id — ошибка (турнир заполнен)
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$w->id}/to-moderation")
            ->assertStatus(422);

        $this->assertSame('waiting', $t->participants()->where('user_id', $w->id)->first()->pivot->status);
    }

    public function test_full_with_choice_swaps(): void
    {
        [$admin, $t] = $this->mk(2);
        $r1 = User::factory()->create(['name' => 'R1']);
        $r2 = User::factory()->create(['name' => 'R2']);
        $t->participants()->attach($r1->id, ['status' => 'registered']);
        $t->participants()->attach($r2->id, ['status' => 'registered']);
        $w = User::factory()->create(['name' => 'W']);
        $t->participants()->attach($w->id, ['status' => 'waiting']);
        Sanctum::actingAs($admin);

        $this->postJson(
            "/api/mobile/admin/tournaments/{$t->id}/participants/{$w->id}/to-moderation",
            ['demote_user_id' => $r2->id]
        )->assertOk();

        // W поднят на модерацию, R2 отправлен в лист ожидания, R1 не тронут
        $this->assertSame('pending', $t->participants()->where('user_id', $w->id)->first()->pivot->status);
        $this->assertSame('waiting', $t->participants()->where('user_id', $r2->id)->first()->pivot->status);
        $this->assertSame('registered', $t->participants()->where('user_id', $r1->id)->first()->pivot->status);
    }

    public function test_not_waiting_rejected(): void
    {
        [$admin, $t] = $this->mk(4);
        $u = User::factory()->create();
        $t->participants()->attach($u->id, ['status' => 'registered']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$u->id}/to-moderation")
            ->assertStatus(422);
    }
}
