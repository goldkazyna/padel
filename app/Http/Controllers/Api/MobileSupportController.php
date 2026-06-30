<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketAttachment;
use App\Support\WebpConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileSupportController extends Controller
{
    /** Список тикетов игрока. */
    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::where('user_id', $request->user()->id)
            ->withCount(['messages as unread_count' => function ($q) {
                $q->where('author_type', 'support')->whereNull('read_at');
            }])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn ($t) => $this->formatTicketSummary($t));

        return response()->json(['tickets' => $tickets]);
    }

    /** Создать тикет с первым сообщением (+фото). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'category' => ['nullable', 'in:' . implode(',', SupportTicket::CATEGORIES)],
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'file|mimes:jpeg,jpg,png,webp,pdf|max:10240',
        ]);

        $ticket = DB::transaction(function () use ($request, $data) {
            $ticket = SupportTicket::create([
                'user_id' => $request->user()->id,
                'subject' => $data['subject'],
                'category' => $data['category'] ?? null,
                'status' => 'open',
                'last_message_at' => now(),
            ]);

            $this->createMessage($ticket, 'player', $request->user()->id, $data['body'], $request->file('photos', []));

            return $ticket;
        });

        return response()->json([
            'success' => true,
            'ticket' => $this->formatTicketDetail($ticket->fresh()),
        ]);
    }

    /** Детали тикета + отметка ответов поддержки прочитанными. */
    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorizeOwner($request, $ticket);

        $ticket->messages()
            ->where('author_type', 'support')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // Гасим связанные support-уведомления в «колокольчике».
        Notification::where('user_id', $request->user()->id)
            ->where('category', 'support')
            ->where('data->ticket_id', $ticket->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ticket' => $this->formatTicketDetail($ticket)]);
    }

    /** Дописать сообщение игрока (+фото). Переоткрывает закрытый тикет. */
    public function addMessage(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorizeOwner($request, $ticket);

        $data = $request->validate([
            'body' => 'required|string',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'file|mimes:jpeg,jpg,png,webp,pdf|max:10240',
        ]);

        DB::transaction(function () use ($request, $ticket, $data) {
            $this->createMessage($ticket, 'player', $request->user()->id, $data['body'], $request->file('photos', []));

            $ticket->update([
                'status' => 'open',
                'last_message_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'ticket' => $this->formatTicketDetail($ticket->fresh()),
        ]);
    }

    /** Кол-во непрочитанных ответов поддержки (для бейджа кнопки). */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = SupportTicketMessage::where('author_type', 'support')
            ->whereNull('read_at')
            ->whereHas('ticket', fn ($q) => $q->where('user_id', $request->user()->id))
            ->count();

        return response()->json(['count' => $count]);
    }

    // -----------------------------------------------------------------

    private function createMessage(SupportTicket $ticket, string $authorType, ?int $authorId, string $body, array $photos): SupportTicketMessage
    {
        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'author_type' => $authorType,
            'author_id' => $authorId,
            'body' => $body,
            'read_at' => null,
            'created_at' => now(),
        ]);

        foreach ($photos as $photo) {
            $ext = strtolower($photo->getClientOriginalExtension());
            // PDF сохраняем как есть, изображения конвертируем в webp.
            $path = $ext === 'pdf'
                ? $photo->store("support/{$ticket->id}", 'public')
                : WebpConverter::store($photo, "support/{$ticket->id}");
            SupportTicketAttachment::create([
                'support_ticket_message_id' => $message->id,
                'path' => $path,
                'created_at' => now(),
            ]);
        }

        return $message;
    }

    private function authorizeOwner(Request $request, SupportTicket $ticket): void
    {
        abort_unless($ticket->user_id === $request->user()->id, 403, 'Нет доступа к этому обращению');
    }

    private function formatTicketSummary(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'is_urgent' => (bool) $ticket->is_urgent,
            'category' => $ticket->category,
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
            'unread_count' => (int) ($ticket->unread_count ?? 0),
        ];
    }

    private function formatTicketDetail(SupportTicket $ticket): array
    {
        $messages = $ticket->messages()->with('attachments')->get()->map(fn ($m) => [
            'id' => $m->id,
            'author_type' => $m->author_type,
            'body' => $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
            'attachments' => $m->attachments->map(fn ($a) => [
                'url' => $a->url,
                'type' => $a->type,
                'name' => $a->name,
            ])->values(),
        ]);

        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'is_urgent' => (bool) $ticket->is_urgent,
            'category' => $ticket->category,
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
            'messages' => $messages,
        ];
    }
}
