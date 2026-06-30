<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\FCMNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SupportTicketAdminTest extends TestCase
{
    use RefreshDatabase;

    private function fakePush(): void
    {
        $mock = Mockery::mock(FCMNotificationService::class);
        $mock->shouldReceive('sendToUser')->andReturn(true);
        $this->instance(FCMNotificationService::class, $mock);
    }

    private function ticketFrom(User $player): SupportTicket
    {
        $ticket = SupportTicket::create([
            'user_id' => $player->id, 'subject' => 'Вопрос', 'status' => 'open',
            'last_message_at' => now(),
        ]);
        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id, 'author_type' => 'player',
            'author_id' => $player->id, 'body' => 'Текст обращения', 'created_at' => now(),
        ]);
        return $ticket;
    }

    public function test_super_admin_sees_tickets_index(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $player = User::factory()->create();
        $this->ticketFrom($player);

        $this->actingAs($admin)->get('/admin/tickets')
            ->assertOk()
            ->assertSee('Вопрос');
    }

    public function test_non_super_admin_forbidden(): void
    {
        $clubAdmin = User::factory()->create(['role' => 'club_admin']);
        $this->actingAs($clubAdmin)->get('/admin/tickets')->assertStatus(403);
    }

    public function test_reply_creates_message_notification_and_sets_answered(): void
    {
        $this->fakePush();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $player = User::factory()->create();
        $ticket = $this->ticketFrom($player);

        $this->actingAs($admin)
            ->post("/admin/tickets/{$ticket->id}/reply", ['body' => 'Разобрались, исправили'])
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('answered', $ticket->status);

        $reply = $ticket->messages()->where('author_type', 'support')->first();
        $this->assertNotNull($reply);
        $this->assertSame('Разобрались, исправили', $reply->body);
        $this->assertNull($reply->read_at); // непрочитано игроком

        $this->assertDatabaseHas('notifications', [
            'user_id' => $player->id,
            'category' => 'support',
            'type' => 'support_reply',
        ]);
    }

    public function test_close_and_reopen(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $player = User::factory()->create();
        $ticket = $this->ticketFrom($player);

        $this->actingAs($admin)->post("/admin/tickets/{$ticket->id}/close")->assertRedirect();
        $this->assertSame('closed', $ticket->fresh()->status);

        $this->actingAs($admin)->post("/admin/tickets/{$ticket->id}/reopen")->assertRedirect();
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_toggle_urgent(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $ticket = $this->ticketFrom(User::factory()->create());

        $this->actingAs($admin)->post("/admin/tickets/{$ticket->id}/urgent")->assertRedirect();
        $this->assertTrue((bool) $ticket->fresh()->is_urgent);

        $this->actingAs($admin)->post("/admin/tickets/{$ticket->id}/urgent")->assertRedirect();
        $this->assertFalse((bool) $ticket->fresh()->is_urgent);
    }

    public function test_set_category(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $ticket = $this->ticketFrom(User::factory()->create());

        $this->actingAs($admin)
            ->post("/admin/tickets/{$ticket->id}/category", ['category' => 'Аккаунт'])
            ->assertRedirect();
        $this->assertSame('Аккаунт', $ticket->fresh()->category);
    }

    public function test_invalid_category_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $ticket = $this->ticketFrom(User::factory()->create());

        $this->actingAs($admin)
            ->post("/admin/tickets/{$ticket->id}/category", ['category' => 'Чтото'])
            ->assertSessionHasErrors('category');
        $this->assertNull($ticket->fresh()->category);
    }

    public function test_reply_with_close_closes_ticket(): void
    {
        $this->fakePush();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $ticket = $this->ticketFrom(User::factory()->create());

        $this->actingAs($admin)
            ->post("/admin/tickets/{$ticket->id}/reply", ['body' => 'Решено', 'close' => '1'])
            ->assertRedirect();

        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame(1, $ticket->messages()->where('author_type', 'support')->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
