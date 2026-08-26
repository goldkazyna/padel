<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesTournamentChatAccess;
use App\Models\Tournament;
use App\Models\TournamentChatMessage;
use App\Models\TournamentChatRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileTournamentChatController extends Controller
{
    use ResolvesTournamentChatAccess;

    /** Лимит длины сообщения. */
    private const MAX_LEN = 2000;

    /**
     * GET /api/mobile/tournaments/{tournament}/chat/messages
     * after_id — только новее (опрос / pull-to-refresh); before_id — история вверх.
     */
    public function index(Request $request, Tournament $tournament): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $this->chatIsAdmin($user, $tournament);
        $isParticipant = $this->chatIsParticipant($user, $tournament);
        abort_unless($this->chatCanRead($tournament, $isAdmin, $isParticipant), 403, 'Чат недоступен');

        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $afterId = $request->query('after_id');
        $beforeId = $request->query('before_id');

        $query = TournamentChatMessage::where('tournament_id', $tournament->id)->with('user');

        if ($afterId !== null) {
            // Только новые: по возрастанию, без лимита сверху (их обычно немного).
            $messages = $query->where('id', '>', (int) $afterId)->orderBy('id')->get();
        } elseif ($beforeId !== null) {
            // История вверх: берём страницу старее beforeId, отдаём по возрастанию.
            $messages = $query->where('id', '<', (int) $beforeId)
                ->orderByDesc('id')->limit($limit)->get()->sortBy('id')->values();
        } else {
            // Последняя страница: последние N, отдаём по возрастанию.
            $messages = $query->orderByDesc('id')->limit($limit)->get()->sortBy('id')->values();
        }

        $adminIds = $this->chatAdminUserIds($tournament);

        return response()->json([
            'messages' => $messages->map(fn ($m) => $this->formatMessage($m, $adminIds, $user?->id))->values(),
        ]);
    }

    /**
     * POST /api/mobile/tournaments/{tournament}/chat/messages  body: { text }
     */
    /** Сколько секунд одинаковый текст от того же автора считается повтором. */
    private const REPEAT_WINDOW = 15;

    public function store(Request $request, Tournament $tournament): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $this->chatIsAdmin($user, $tournament);
        $isParticipant = $this->chatIsParticipant($user, $tournament);
        abort_unless($this->chatCanWrite($tournament, $isAdmin, $isParticipant), 403, 'Нет прав писать в чат');

        $data = $request->validate([
            'text' => 'required|string|max:' . self::MAX_LEN,
        ]);

        $text = trim($data['text']);

        // Двойной тап по кнопке отправлял два одинаковых сообщения — и два
        // пуша участникам. Повтор того же текста в первые секунды считаем
        // тем же сообщением и возвращаем уже созданное.
        $justSent = TournamentChatMessage::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->where('text', $text)
            ->where('created_at', '>=', now()->subSeconds(self::REPEAT_WINDOW))
            ->latest('id')
            ->first();

        if ($justSent) {
            return response()->json([
                'message' => $this->formatMessage(
                    $justSent->load('user'),
                    $this->chatAdminUserIds($tournament),
                    $user->id
                ),
            ]);
        }

        $message = TournamentChatMessage::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'text' => $text,
            'created_at' => now(),
        ]);
        $message->setRelation('user', $user);

        // Организатор написал в чат — пуш участникам (у кого включена настройка).
        if ($isAdmin) {
            try {
                app(\App\Services\TournamentPushService::class)
                    ->sendChatMessage($tournament, $user, $message->text);
            } catch (\Throwable $e) {
                report($e); // не роняем отправку сообщения из-за ошибки пуша
            }
        }

        $adminIds = $this->chatAdminUserIds($tournament);

        return response()->json([
            'message' => $this->formatMessage($message, $adminIds, $user->id),
        ]);
    }

    /**
     * DELETE /api/mobile/tournaments/{tournament}/chat/messages/{message}
     * Автор удаляет своё; организатор — любое.
     */
    public function destroy(Request $request, Tournament $tournament, int $message): JsonResponse
    {
        $user = $request->user();
        $msg = TournamentChatMessage::where('tournament_id', $tournament->id)
            ->where('id', $message)
            ->firstOrFail();

        $isOwner = (int) $msg->user_id === (int) $user->id;
        abort_unless($isOwner || $this->chatIsAdmin($user, $tournament), 403, 'Нельзя удалить это сообщение');

        $msg->delete();

        // 200 с JSON-телом (НЕ 204) — клиент парсит тело как JSON.
        return response()->json(['deleted' => true]);
    }

    /**
     * GET /api/mobile/tournaments/{tournament}/chat/unread-count
     * Лёгкий счётчик непрочитанного для бейджа (опрос на экране турнира).
     */
    public function unreadCount(Request $request, Tournament $tournament): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $this->chatIsAdmin($user, $tournament);
        $isParticipant = $this->chatIsParticipant($user, $tournament);
        if (!$this->chatCanRead($tournament, $isAdmin, $isParticipant)) {
            return response()->json(['unread_count' => 0]);
        }
        return response()->json([
            'unread_count' => $this->chatUnreadCount($tournament, $user),
        ]);
    }

    /**
     * POST /api/mobile/tournaments/{tournament}/chat/read  body: { last_message_id }
     */
    public function read(Request $request, Tournament $tournament): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'last_message_id' => 'required|integer|min:0',
        ]);

        $existing = TournamentChatRead::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->first();

        // Не откатываем назад: сохраняем максимум прочитанного.
        $newLast = $existing
            ? max((int) $existing->last_read_message_id, (int) $data['last_message_id'])
            : (int) $data['last_message_id'];

        TournamentChatRead::updateOrCreate(
            ['tournament_id' => $tournament->id, 'user_id' => $user->id],
            ['last_read_message_id' => $newLast],
        );

        return response()->json(['success' => true]);
    }

    // -----------------------------------------------------------------

    private function formatMessage(TournamentChatMessage $m, array $adminIds, ?int $currentUserId): array
    {
        $author = $m->user;

        return [
            'id' => $m->id,
            'user' => [
                'id' => $author?->id ?? $m->user_id,
                'name' => $author?->name ?? '',
                'avatar' => $author?->avatar,
                'level' => $author?->level,
            ],
            'text' => $m->text,
            'is_admin' => in_array((int) $m->user_id, $adminIds, true),
            'is_mine' => $currentUserId !== null && (int) $m->user_id === (int) $currentUserId,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
