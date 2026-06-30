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
    /** Список + (опц.) выбранный тикет в мастер-детали. */
    public function index(Request $request)
    {
        return $this->render($request, null);
    }

    /** Тот же экран, но с выбранным тикетом справа. */
    public function show(Request $request, SupportTicket $ticket)
    {
        $ticket->messages()
            ->where('author_type', 'player')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->render($request, $ticket->fresh());
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['body' => 'required|string']);
        $close = $request->boolean('close');

        SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_type' => 'support',
            'author_id' => $request->user()->id,
            'body' => $data['body'],
            'read_at' => null,
            'created_at' => now(),
        ]);

        $ticket->update([
            'status' => $close ? 'closed' : 'answered',
            'last_message_at' => now(),
        ]);

        $this->notifyPlayer($ticket);

        return $this->backToTicket($request, $ticket, 'Ответ отправлен');
    }

    public function close(Request $request, SupportTicket $ticket)
    {
        $ticket->update(['status' => 'closed']);
        return $this->backToTicket($request, $ticket, 'Тикет закрыт');
    }

    public function reopen(Request $request, SupportTicket $ticket)
    {
        $ticket->update(['status' => 'open']);
        return $this->backToTicket($request, $ticket, 'Тикет открыт');
    }

    /** Переключить «срочный». */
    public function urgent(Request $request, SupportTicket $ticket)
    {
        $ticket->update(['is_urgent' => !$ticket->is_urgent]);
        return $this->backToTicket($request, $ticket,
            $ticket->is_urgent ? 'Помечен срочным' : 'Срочность снята');
    }

    /** Поставить/снять категорию (метку). */
    public function category(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'category' => ['nullable', 'in:' . implode(',', SupportTicket::CATEGORIES)],
        ]);
        $ticket->update(['category' => $data['category'] ?? null]);
        return $this->backToTicket($request, $ticket, 'Метка обновлена');
    }

    // -----------------------------------------------------------------

    private function render(Request $request, ?SupportTicket $selected)
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $listQuery = SupportTicket::with('user')
            ->withCount(['messages as player_unread_count' => function ($qq) {
                $qq->where('author_type', 'player')->whereNull('read_at');
            }])
            ->when($status, fn ($x) => $x->where('status', $status))
            ->when($q !== '', function ($x) use ($q) {
                $x->where(function ($w) use ($q) {
                    $w->where('subject', 'like', "%{$q}%")
                      ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"));
                });
            })
            ->orderByDesc('is_urgent')
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 ELSE 2 END")
            ->orderByDesc('last_message_at');

        $tickets = $listQuery->limit(200)->get();

        // Если ничего не выбрано — показываем первый из списка.
        if (!$selected && $tickets->isNotEmpty()) {
            $selected = SupportTicket::with(['user', 'messages.attachments'])->find($tickets->first()->id);
        } elseif ($selected) {
            $selected->load(['user', 'messages.attachments']);
        }

        $counts = [
            'all' => SupportTicket::count(),
            'open' => SupportTicket::where('status', 'open')->count(),
            'answered' => SupportTicket::where('status', 'answered')->count(),
            'closed' => SupportTicket::where('status', 'closed')->count(),
        ];

        return view('admin.tickets.index', [
            'tickets' => $tickets,
            'selected' => $selected,
            'counts' => $counts,
            'status' => $status,
            'q' => $q,
            'categories' => SupportTicket::CATEGORIES,
        ]);
    }

    private function backToTicket(Request $request, SupportTicket $ticket, string $msg)
    {
        return redirect()
            ->route('admin.tickets.show', array_filter([
                'ticket' => $ticket->id,
                'status' => $request->query('status'),
                'q' => $request->query('q'),
            ]))
            ->with('success', $msg);
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

        try {
            app(FCMNotificationService::class)->sendToUser(
                $ticket->user,
                $title,
                $body,
                ['type' => 'support_reply', 'ticket_id' => (string) $ticket->id]
            );
        } catch (\Throwable $e) {
            // пуш не критичен
        }
    }
}
