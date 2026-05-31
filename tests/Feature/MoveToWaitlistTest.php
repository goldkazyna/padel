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

    public function test_move_promotes_chosen_waiter(): void
    {
        [$admin, $t, $player] = $this->setup3();
        $t->update(['moderation_minutes' => 30]); // у турнира есть таймер
        $w1 = User::factory()->create(['name' => 'W1']);
        $w2 = User::factory()->create(['name' => 'W2']);
        $t->participants()->attach($w1->id, ['status' => 'waiting', 'created_at' => now()->subHours(2)]);
        $t->participants()->attach($w2->id, ['status' => 'waiting', 'created_at' => now()->subHour()]);
        $t->participants()->attach($player->id, ['status' => 'registered']);
        Sanctum::actingAs($admin);

        // выбираем НЕ первого, а W2
        $this->postJson(
            "/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/to-waitlist",
            ['promote_user_id' => $w2->id]
        )->assertOk();

        // W2 поднят на модерацию со свежим таймером
        $w2Row = $t->participants()->where('user_id', $w2->id)->first();
        $this->assertSame('pending', $w2Row->pivot->status);
        $this->assertNotNull($w2Row->pivot->moderation_deadline);

        // W1 остался в листе, перемещённый — в листе
        $this->assertSame('waiting', $t->participants()->where('user_id', $w1->id)->first()->pivot->status);
        $this->assertSame('waiting', $t->participants()->where('user_id', $player->id)->first()->pivot->status);
    }

    public function test_move_without_choice_does_not_promote(): void
    {
        [$admin, $t, $player] = $this->setup3();
        $w = User::factory()->create(['name' => 'Очередник']);
        $t->participants()->attach($w->id, ['status' => 'waiting', 'created_at' => now()->subHour()]);
        $t->participants()->attach($player->id, ['status' => 'registered']);
        Sanctum::actingAs($admin);

        // без promote_user_id — просто перенос, никого не продвигаем
        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/to-waitlist")
            ->assertOk();

        $this->assertSame('waiting', $t->participants()->where('user_id', $w->id)->first()->pivot->status);
        $this->assertSame('waiting', $t->participants()->where('user_id', $player->id)->first()->pivot->status);
        $this->assertSame(0, $t->participants()->wherePivot('status', 'pending')->count());
    }

    public function test_participants_endpoint_orders_waitlist_fifo(): void
    {
        [$admin, $t, $player] = $this->setup3();
        $w1 = User::factory()->create(['name' => 'W1']); // самый старый → продвинется
        $w2 = User::factory()->create(['name' => 'W2']);
        $t->participants()->attach($w1->id, ['status' => 'waiting', 'created_at' => now()->subHours(2)]);
        $t->participants()->attach($w2->id, ['status' => 'waiting', 'created_at' => now()->subHour()]);
        $t->participants()->attach($player->id, ['status' => 'registered', 'created_at' => now()->subDay()]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/mobile/admin/tournaments/{$t->id}/participants/{$player->id}/to-waitlist")
            ->assertOk();

        // без выбора продвижения нет: в листе W1 (старший) … W2 … перемещённый (последним)
        $res = $this->getJson("/api/mobile/admin/tournaments/{$t->id}/participants")->assertOk();
        $waiting = collect($res->json('participants'))->where('status', 'waiting')->values();
        $this->assertSame($w1->id, $waiting->first()['id']);
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
