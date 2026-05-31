<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Club;
use App\Models\User;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MoveToWaitlistTest extends TestCase
{
    use RefreshDatabase;

    private function setup3(): array
    {
        $club = Club::create(['name' => 'C', 'address' => 'A']);
        $admin = User::factory()->create(['role' => 'club_admin']);
        $admin->adminClubs()->attach($club->id);
        $t = Tournament::create([
            'club_id' => $club->id, 'name' => 'Кубок', 'type' => 'americano',
            'status' => 'open', 'max_participants' => 4, 'waitlist_size' => 4,
            'start_date' => now()->addDays(3), 'registration_deadline' => now()->addDay(),
        ]);
        $player = User::factory()->create(['level' => 3]);
        return [$admin, $t, $player];
    }

    public function test_admin_moves_registered_to_waitlist(): void
    {
        [$admin, $t, $player] = $this->setup3();
        $t->participants()->attach($player->id, ['status' => 'registered']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/to-waitlist")
            ->assertOk();

        $row = $t->participants()->where('user_id', $player->id)->first();
        $this->assertSame('waiting', $row->pivot->status);
    }

    public function test_pending_with_timer_moved_to_waitlist_clears_deadline(): void
    {
        [$admin, $t, $player] = $this->setup3();
        $t->participants()->attach($player->id, [
            'status' => 'pending', 'moderation_deadline' => now()->addHour(),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/to-waitlist")
            ->assertOk();

        $row = $t->participants()->where('user_id', $player->id)->first();
        $this->assertSame('waiting', $row->pivot->status);
        $this->assertNull($row->pivot->moderation_deadline);
    }

    public function test_moved_to_end_of_waitlist(): void
    {
        [$admin, $t, $player] = $this->setup3();
        // в листе ожидания уже есть старичок
        $old = User::factory()->create();
        $t->participants()->attach($old->id, ['status' => 'waiting', 'created_at' => now()->subHour()]);
        $t->participants()->attach($player->id, ['status' => 'registered']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/to-waitlist")
            ->assertOk();

        // первый в очереди (по created_at) — старичок, не перемещённый
        $first = $t->participants()->wherePivot('status', 'waiting')
            ->orderBy('tournament_participants.created_at')->first();
        $this->assertSame($old->id, $first->id);
    }

    public function test_participants_endpoint_orders_waitlist_fifo(): void
    {
        [$admin, $t, $player] = $this->setup3();
        $old = User::factory()->create(['name' => 'Старичок']);
        $t->participants()->attach($old->id, ['status' => 'waiting', 'created_at' => now()->subHour()]);
        $t->participants()->attach($player->id, ['status' => 'registered', 'created_at' => now()->subDay()]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/to-waitlist")
            ->assertOk();

        $res = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/participants")->assertOk();
        $waiting = collect($res->json('participants'))->where('status', 'waiting')->values();
        // первым в листе — старичок, перемещённый — последним
        $this->assertSame($old->id, $waiting->first()['id']);
        $this->assertSame($player->id, $waiting->last()['id']);
    }

    public function test_already_waiting_rejected(): void
    {
        [$admin, $t, $player] = $this->setup3();
        $t->participants()->attach($player->id, ['status' => 'waiting']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/to-waitlist")
            ->assertStatus(422);
    }
}
