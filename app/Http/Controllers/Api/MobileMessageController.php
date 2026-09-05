<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationRead;
use App\Models\Notification;
use App\Models\PlayerFollow;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\FCMNotificationService;
use App\Support\AmigoActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Личная переписка один-на-один.
 *
 * Механика взята с турнирного чата: страницы через after_id/before_id,
 * отметка прочтения, защита от повтора одного и того же текста. Вебсокетов
 * нет и не нужно — приложение опрашивает при открытом экране.
 *
 * Писать можно любому игроку (решение продукта), поэтому здесь же живут
 * лимиты на отправку, а блокировка и жалоба — рядом, в своих контроллерах.
 */
class MobileMessageController extends Controller
{
    private const MAX_LEN = 2000;

    /** Сколько секунд одинаковый текст от того же автора считается повтором. */
    private const REPEAT_WINDOW = 15;

    /** Потолок на отправку: столько сообщений в минуту от одного человека. */
    private const RATE_PER_MINUTE = 20;

    /** Столько первых сообщений разным незнакомым людям в час. */
    private const COLD_STARTS_PER_HOUR = 5;

    /** GET /messages — список диалогов. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = Conversation::where(function ($q) use ($user) {
            $q->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id);
        })
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $otherIds = $conversations->map(fn ($c) => $c->otherUserId($user->id))->all();
        $blocked = $this->blockedIds($user->id);
        $others = User::whereIn('id', $otherIds)->get(['id', 'name', 'avatar', 'level'])->keyBy('id');
        $statuses = AmigoActivity::cached(array_values(array_diff($otherIds, $blocked)));

        $lastMessages = ConversationMessage::whereIn('conversation_id', $conversations->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        $reads = ConversationRead::where('user_id', $user->id)
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->pluck('last_read_message_id', 'conversation_id');

        $rows = [];
        foreach ($conversations as $conversation) {
            $otherId = $conversation->otherUserId($user->id);
            if (in_array($otherId, $blocked, true)) {
                continue;
            }

            $other = $others[$otherId] ?? null;
            if (!$other) {
                continue;
            }

            $last = $lastMessages[$conversation->id] ?? null;
            $lastReadId = (int) ($reads[$conversation->id] ?? 0);

            $rows[] = [
                'conversation_id' => $conversation->id,
                'player' => [
                    'id' => $other->id,
                    'name' => $other->name,
                    'avatar' => $other->avatar,
                    'level' => $other->level,
                    'status' => $statuses[$other->id] ?? null,
                ],
                'last_message' => $last ? [
                    'text' => $last->text,
                    'is_mine' => (int) $last->user_id === (int) $user->id,
                    'created_at' => $last->created_at?->toIso8601String(),
                ] : null,
                'unread' => ConversationMessage::where('conversation_id', $conversation->id)
                    ->where('user_id', '!=', $user->id)
                    ->where('id', '>', $lastReadId)
                    ->count(),
            ];
        }

        // Непрочитанные наверх — иначе важное тонет под свежей болтовнёй.
        usort($rows, fn ($a, $b) => ($b['unread'] <=> $a['unread']));

        return response()->json(['success' => true, 'conversations' => $rows]);
    }

    /** GET /messages/unread-count — бейдж в шапке профиля. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'count' => $this->totalUnread($request->user()->id),
        ]);
    }

    /** GET /messages/{user} — переписка с игроком. */
    public function show(Request $request, User $user): JsonResponse
    {
        $me = $request->user();

        if ($me->id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Себе не пишем'], 422);
        }

        $blockedByMe = UserBlock::where('user_id', $me->id)->where('blocked_user_id', $user->id)->exists();
        $blockedMe = UserBlock::where('user_id', $user->id)->where('blocked_user_id', $me->id)->exists();

        $conversation = Conversation::between($me->id, $user->id);
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $afterId = $request->query('after_id');
        $beforeId = $request->query('before_id');

        $query = ConversationMessage::where('conversation_id', $conversation->id);

        if ($afterId !== null) {
            $messages = $query->where('id', '>', (int) $afterId)->orderBy('id')->get();
        } elseif ($beforeId !== null) {
            $messages = $query->where('id', '<', (int) $beforeId)
                ->orderByDesc('id')->limit($limit)->get()->sortBy('id')->values();
        } else {
            $messages = $query->orderByDesc('id')->limit($limit)->get()->sortBy('id')->values();
        }

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'player' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'level' => $user->level,
                'rating' => (int) $user->rating,
                'status' => $blockedByMe || $blockedMe
                    ? null
                    : (AmigoActivity::cached([$user->id])[$user->id] ?? null),
                'is_amigo' => PlayerFollow::where('follower_id', $me->id)
                    ->where('following_id', $user->id)->exists(),
            ],
            'blocked_by_me' => $blockedByMe,
            'blocked_me' => $blockedMe,
            // Правила показываем один раз — пока в переписке нет ни одного сообщения.
            'show_rules' => $conversation->messages()->count() === 0,
            'messages' => $messages->map(fn ($m) => $this->formatMessage($m, $me->id))->values(),
        ]);
    }

    /** POST /messages/{user} — отправить. */
    public function store(Request $request, User $user): JsonResponse
    {
        $me = $request->user();

        if ($me->id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Себе не пишем'], 422);
        }

        $data = $request->validate([
            'text' => 'required|string|max:' . self::MAX_LEN,
        ]);
        $text = trim($data['text']);

        if ($text === '') {
            return response()->json(['success' => false, 'message' => 'Пустое сообщение'], 422);
        }

        if (UserBlock::betweenExists($me->id, $user->id)) {
            return response()->json(['success' => false, 'message' => 'Написать этому игроку нельзя'], 403);
        }

        if ($limitMessage = $this->rateLimitMessage($me->id, $user->id)) {
            return response()->json(['success' => false, 'message' => $limitMessage], 429);
        }

        $conversation = Conversation::between($me->id, $user->id);

        // Тот же текст, отправленный дважды подряд, — это дрожащий палец.
        $repeat = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->where('text', $text)
            ->where('created_at', '>=', now()->subSeconds(self::REPEAT_WINDOW))
            ->exists();

        if ($repeat) {
            return response()->json(['success' => false, 'message' => 'Это сообщение уже отправлено'], 422);
        }

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $me->id,
            'text' => $text,
            'created_at' => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        $this->notifyMessage($user, $me, $text);

        return response()->json([
            'success' => true,
            'message' => $this->formatMessage($message, $me->id),
        ]);
    }

    /** POST /messages/{user}/read — дочитал. */
    public function read(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        $conversation = Conversation::between($me->id, $user->id);

        $lastId = (int) ConversationMessage::where('conversation_id', $conversation->id)->max('id');

        ConversationRead::updateOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $me->id],
            ['last_read_message_id' => $lastId]
        );

        return response()->json(['success' => true, 'unread_total' => $this->totalUnread($me->id)]);
    }

    /** DELETE /messages/{message} — удалить своё сообщение. */
    public function destroy(Request $request, ConversationMessage $message): JsonResponse
    {
        if ((int) $message->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Чужое сообщение не удалить'], 403);
        }

        $message->delete();

        return response()->json(['success' => true]);
    }

    // ===== внутреннее =====

    private function formatMessage(ConversationMessage $message, int $meId): array
    {
        return [
            'id' => $message->id,
            'text' => $message->text,
            'is_mine' => (int) $message->user_id === $meId,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** @return array<int, int> */
    private function blockedIds(int $userId): array
    {
        $mine = UserBlock::where('user_id', $userId)->pluck('blocked_user_id');
        $theirs = UserBlock::where('blocked_user_id', $userId)->pluck('user_id');

        return $mine->merge($theirs)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function totalUnread(int $userId): int
    {
        $conversations = Conversation::where(function ($q) use ($userId) {
            $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId);
        })->pluck('id');

        if ($conversations->isEmpty()) {
            return 0;
        }

        $reads = ConversationRead::where('user_id', $userId)
            ->whereIn('conversation_id', $conversations)
            ->pluck('last_read_message_id', 'conversation_id');

        $total = 0;
        foreach ($conversations as $conversationId) {
            $total += ConversationMessage::where('conversation_id', $conversationId)
                ->where('user_id', '!=', $userId)
                ->where('id', '>', (int) ($reads[$conversationId] ?? 0))
                ->count();
        }

        return $total;
    }

    /**
     * Лимиты на отправку.
     *
     * Первый — от дрожащей руки и ботов. Второй важнее: он не даёт делать
     * рассылку «привет, я тренер» по незнакомым людям, ради которой открытую
     * переписку обычно и портят.
     */
    private function rateLimitMessage(int $meId, int $toId): ?string
    {
        $myConversationIds = Conversation::where(function ($q) use ($meId) {
            $q->where('user_one_id', $meId)->orWhere('user_two_id', $meId);
        })->pluck('id');

        $lastMinute = ConversationMessage::whereIn('conversation_id', $myConversationIds)
            ->where('user_id', $meId)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($lastMinute >= self::RATE_PER_MINUTE) {
            return 'Слишком много сообщений подряд. Подождите минуту';
        }

        // «Холодный старт» — первое сообщение человеку, с которым переписки ещё не было.
        $conversation = Conversation::between($meId, $toId);
        $isColdStart = !ConversationMessage::where('conversation_id', $conversation->id)->exists();

        if (!$isColdStart) {
            return null;
        }

        $coldStarts = ConversationMessage::whereIn('conversation_id', $myConversationIds)
            ->where('user_id', $meId)
            ->where('created_at', '>=', now()->subHour())
            ->get()
            ->groupBy('conversation_id')
            // Диалог считается «начатым за час», если в нём есть только мои свежие сообщения.
            ->filter(function ($messages, $conversationId) use ($meId) {
                return ConversationMessage::where('conversation_id', $conversationId)
                    ->where('user_id', '!=', $meId)
                    ->doesntExist();
            })
            ->count();

        if ($coldStarts >= self::COLD_STARTS_PER_HOUR) {
            return 'Слишком много первых сообщений за час. Попробуйте позже';
        }

        return null;
    }

    private function notifyMessage(User $to, User $from, string $text): void
    {
        $preview = mb_strimwidth($text, 0, 120, '…');

        Notification::create([
            'user_id' => $to->id,
            'title' => $from->name,
            'body' => $preview,
            'type' => 'direct_message',
            'category' => 'message',
            'data' => ['user_id' => $from->id],
        ]);

        if (!$to->notify_messages) {
            return;
        }

        app(FCMNotificationService::class)->sendToUser($to, $from->name, $preview, [
            'type' => 'direct_message',
            'user_id' => (string) $from->id,
        ]);
    }
}
