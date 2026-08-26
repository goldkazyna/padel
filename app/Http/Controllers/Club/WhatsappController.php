<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\WhatsappMessage;
use App\Support\WhatsappSla;
use Illuminate\Http\Request;

/**
 * Переписка WhatsApp в CRM.
 *
 * Интеграция «мягкая»: сообщения приходят вебхуком Whapi.Cloud, здесь их
 * только читают. Ничего не отправляется — номер клуба живой, и рассылки
 * из CRM появятся отдельным решением.
 */
class WhatsappController extends Controller
{
    private function getClub(): ?Club
    {
        $user = auth()->user();
        if (!$user) return null;
        if ($user->isSuperAdmin()) return Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();

        return $user->adminClubs()->first();
    }

    /** Список диалогов: по одному на номер, свежие сверху. */
    public function index(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $search = trim((string) $request->get('search'));

        $query = WhatsappMessage::where('club_id', $club->id);
        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('author_name', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
                if ($digits !== '') {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        // Диалоги собираем в PHP: переписки пока немного, а группировка
        // с «последним сообщением» в SQL читается куда хуже.
        $chats = $query->orderByDesc('sent_at')->limit(2000)->get()
            ->groupBy('phone')
            ->map(function ($messages, $phone) {
                $last = $messages->first();

                return [
                    'phone' => $phone,
                    'name' => $messages->firstWhere('from_me', false)?->author_name,
                    'last' => $last,
                    'total' => $messages->count(),
                    'incoming' => $messages->where('from_me', false)->count(),
                ];
            })
            ->sortByDesc(fn ($chat) => $chat['last']->sent_at)
            ->values();

        // Имена клиентов подтягиваем одним запросом: у каждого сообщения
        // спрашивать базу — верный способ получить сотню запросов на экран.
        $clients = $this->clientsByPhone($club, $chats->pluck('phone')->all());

        return view('club.whatsapp.index', [
            'club' => $club,
            'chats' => $chats,
            'clients' => $clients,
            'search' => $search,
            'total' => WhatsappMessage::where('club_id', $club->id)->count(),
            // Точка отсчёта для опроса: с чем сравнивать «пришло ли новое».
            'lastId' => (int) WhatsappMessage::where('club_id', $club->id)->max('id'),
            'waitingCount' => WhatsappSla::waitingChats($club->id)->count(),
        ]);
    }

    /** Один диалог: сообщения по дням, как в самом мессенджере. */
    public function show(Request $request, string $phone)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $digits = preg_replace('/\D/', '', $phone);
        $messages = WhatsappMessage::where('club_id', $club->id)
            ->where('phone', $digits)
            ->orderBy('sent_at')
            ->get();

        if ($messages->isEmpty()) abort(404);

        // Сутки — местные: сообщение в 02:00 по Алматы это уже новый день,
        // хотя в UTC ещё вчерашний вечер.
        $tz = config('app.schedule_timezone', 'Asia/Almaty');
        $days = $messages->groupBy(fn ($m) => $m->sent_at->timezone($tz)->toDateString());

        return view('club.whatsapp.show', [
            'club' => $club,
            'phone' => $digits,
            'name' => $messages->firstWhere('from_me', false)?->author_name,
            'client' => $this->clientsByPhone($club, [$digits])[substr($digits, -10)] ?? null,
            'days' => $days,
            'messages' => $messages,
        ]);
    }

    /**
     * Кто ждёт ответа прямо сейчас.
     *
     * Экран оперативный, а не аналитический: он нужен, чтобы клиента не
     * потеряли сегодня. Разбор «кто и как долго молчал» — отдельный отчёт.
     */
    public function waiting(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $all = $request->boolean('all');
        $everything = WhatsappSla::waitingChats($club->id);

        // По умолчанию — последние трое суток. Диалог, где клиент написал
        // «спасибо» три недели назад, формально без ответа, но дежурному
        // сегодня он не нужен — иначе список превращается в кладбище.
        $waiting = $all
            ? $everything
            : $everything->filter(fn ($row) => $row['since']->greaterThan(now()->subDays(3)))->values();
        $clients = $this->clientsByPhone($club, $waiting->pluck('phone')->all());

        return view('club.whatsapp.waiting', [
            'club' => $club,
            'waiting' => $waiting,
            'clients' => $clients,
            'overdue' => $waiting->where('overdue', true)->count(),
            'all' => $all,
            'totalWaiting' => $everything->count(),
            'threshold' => WhatsappSla::threshold(),
            'workFrom' => WhatsappSla::workFrom(),
            'workTo' => WhatsappSla::workTo(),
        ]);
    }
    /**
     * Маячок для списка диалогов: id самого свежего сообщения клуба.
     * Вкладка сравнивает его со своим и перерисовывает список, только
     * если что-то действительно пришло.
     */
    public function updates()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        return response()->json([
            'last_id' => (int) WhatsappMessage::where('club_id', $club->id)->max('id'),
        ]);
    }

    /** Сообщения диалога, пришедшие после указанного id. */
    public function chatUpdates(Request $request, string $phone)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $digits = preg_replace('/\D/', '', $phone);
        $after = (int) $request->get('after');
        $tz = config('app.schedule_timezone', 'Asia/Almaty');

        $messages = WhatsappMessage::where('club_id', $club->id)
            ->where('phone', $digits)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'from_me' => (bool) $m->from_me,
                'text' => $m->body ?: $m->preview(),
                // Нетекстовое показываем подписью типа — курсивом, как на сервере.
                'plain' => (bool) $m->body,
                'time' => $m->sent_at->timezone($tz)->format('H:i'),
                'date' => $m->sent_at->timezone($tz)->toDateString(),
                'day' => $m->sent_at->timezone($tz)->locale('ru')->translatedFormat('j F Y'),
            ])->values(),
        ]);
    }
    /**
     * Карточки клиентов по номерам: [последние 10 цифр => ClubClient].
     * Номер в WhatsApp — цифрами, в карточке записан как придётся.
     */
    private function clientsByPhone(Club $club, array $phones): array
    {
        $tails = collect($phones)
            ->map(fn ($p) => substr(preg_replace('/\D/', '', (string) $p), -10))
            ->filter(fn ($p) => strlen($p) === 10)
            ->unique();

        if ($tails->isEmpty()) return [];

        $found = [];
        foreach (ClubClient::where('club_id', $club->id)->get(['id', 'name', 'phone']) as $client) {
            $tail = substr(preg_replace('/\D/', '', (string) $client->phone), -10);
            if (strlen($tail) === 10 && $tails->contains($tail)) {
                $found[$tail] = $client;
            }
        }

        return $found;
    }
}
