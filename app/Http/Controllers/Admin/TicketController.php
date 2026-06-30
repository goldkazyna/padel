<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Services\FCMNotificationService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    /** Список тикетов (открытые/отвеченные сверху). */
    public function index(Request $request)
    {
        $status = $request->query('status');

        $tickets = SupportTicket::with('user')
            ->withCount(['messages as player_unread_count' => function ($q) {
                $q->where('author_type', 'player')->whereNull('read_at');
            }])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 ELSE 2 END")
            ->orderByDesc('last_message_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.tickets.index', compact('tickets', 'status'));
    }

    /** Просмотр тикета + отметка сообщений игрока прочитанными. */
    public function show(SupportTicket $ticket)
    {
        $ticket->load(['user', 'messages.attachments', 'messages.author']);

        $ticket->messages()
            ->where('author_type', 'player')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return view('admin.tickets.show', compact('ticket'));
    }

    /** Ответ поддержки: сообщение + уведомление + пуш игроку. */
    public function reply(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'body' => 'required|string',
        ]);

        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_type' => 'support',
            'author_id' => $request->user()->id,
            'body' => $data['body'],
            'read_at' => null,
            'created_at' => now(),
        ]);

        $ticket->update([
            'status' => 'answered',
            'last_message_at' => now(),
        ]);

        $this->notifyPlayer($ticket);

        return redirect()->route('admin.tickets.show', $ticket)
            ->with('success', 'Ответ отправлен');
    }

    public function close(SupportTicket $ticket)
    {
        $ticket->update(['status' => 'closed']);

        return redirect()->route('admin.tickets.show', $ticket)
            ->with('success', 'Тикет закрыт');
    }

    public function reopen(SupportTicket $ticket)
    {
        $ticket->update(['status' => 'open']);

        return redirect()->route('admin.tickets.show', $ticket)
            ->with('success', 'Тикет открыт');
    }

    private function notifyPlayer(SupportTicket $ticket): void
    {
        $title = 'Ответ службы поддержки';
        $body = 'Вам ответили по обращению «' . $ticket->subject . '»';

        Notification::create([
            'user_id' => $ticket->user_id,
            'title' => $title,
            'body' => $body,
            'type' => 'support_reply',
            'category' => 'support',
            'data' => ['ticket_id' => $ticket->id],
        ]);

        // Пуш не критичен — оборачиваем.
        try {
            app(FCMNotificationService::class)->sendToUser(
                $ticket->user,
                $title,
                $body,
                ['type' => 'support_reply', 'ticket_id' => (string) $ticket->id]
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
