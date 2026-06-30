<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportTicketPlayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_creates_ticket_with_photo_converted_to_webp(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/mobile/support/tickets', [
            'subject' => 'Не проходит оплата',
            'body' => 'При оплате турнира ошибка',
            'photos' => [UploadedFile::fake()->image('err.jpg', 800, 600)],
        ]);

        $res->assertOk()->assertJsonPath('success', true);

        $ticket = SupportTicket::first();
        $this->assertSame('Не проходит оплата', $ticket->subject);
        $this->assertSame('open', $ticket->status);
        $this->assertSame($user->id, $ticket->user_id);

        $msg = $ticket->messages()->first();
        $this->assertSame('player', $msg->author_type);
        $this->assertSame('При оплате турнира ошибка', $msg->body);

        $att = $msg->attachments()->first();
        $this->assertNotNull($att);
        $this->assertStringEndsWith('.webp', $att->path);
        Storage::disk('public')->assertExists($att->path);
    }

    public function test_player_cannot_open_foreign_ticket(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $ticket = SupportTicket::create([
            'user_id' => $owner->id, 'subject' => 'X', 'status' => 'open',
            'last_message_at' => now(),
        ]);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/mobile/support/tickets/{$ticket->id}")->assertStatus(403);
    }

    public function test_adding_message_reopens_closed_ticket(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $ticket = SupportTicket::create([
            'user_id' => $user->id, 'subject' => 'X', 'status' => 'closed',
            'last_message_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/mobile/support/tickets/{$ticket->id}/messages", [
            'body' => 'Снова та же проблема',
        ])->assertOk();

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_unread_count_counts_support_replies_and_clears_on_open(): void
    {
        $user = User::factory()->create();
        $ticket = SupportTicket::create([
            'user_id' => $user->id, 'subject' => 'X', 'status' => 'answered',
            'last_message_at' => now(),
        ]);
        // Ответ поддержки — непрочитан игроком.
        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id, 'author_type' => 'support',
            'author_id' => null, 'body' => 'Ответ', 'read_at' => null,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/mobile/support/unread-count')
            ->assertOk()->assertJsonPath('count', 1);

        // Открытие тикета помечает ответы прочитанными.
        $this->getJson("/api/mobile/support/tickets/{$ticket->id}")->assertOk();

        $this->getJson('/api/mobile/support/unread-count')
            ->assertOk()->assertJsonPath('count', 0);
    }
}
