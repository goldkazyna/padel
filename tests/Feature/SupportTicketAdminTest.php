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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
