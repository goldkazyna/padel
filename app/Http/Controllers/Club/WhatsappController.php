<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\WhatsappMessage;
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

        $days = $messages->groupBy(fn ($m) => $m->sent_at->toDateString());

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
