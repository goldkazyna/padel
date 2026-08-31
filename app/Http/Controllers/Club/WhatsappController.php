<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\ClubClient;
use App\Models\WhatsappMessage;
use App\Models\WhatsappAnalysis;
use App\Services\WhatsappAnalysisService;
use App\Support\WhatsappDayReport;
use App\Support\WhatsappSla;
use Carbon\Carbon;
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

        // Кто ждёт ответа и сколько — считает тот же код, что и экран
        // «Ждут ответа»: в списке это главный признак, а не сноска.
        $waiting = WhatsappSla::waitingChats($club->id)->keyBy('phone');

        // Диалоги собираем в PHP: переписки пока немного, а группировка
        // с «последним сообщением» в SQL читается куда хуже.
        $chats = $query->orderByDesc('sent_at')->limit(2000)->get()
            ->groupBy('phone')
            ->map(function ($messages, $phone) use ($waiting) {
                $last = $messages->first();
                $wait = $waiting[$phone] ?? null;

                return [
                    'phone' => $phone,
                    'name' => $messages->firstWhere('from_me', false)?->author_name,
                    'last' => $last,
                    // В превью — последнее живое сообщение: строка
                    // «служебное событие» о диалоге не говорит ничего.
                    'preview' => $messages->first(
                        fn ($m) => !in_array($m->type, ['action', 'system', 'notification'], true)
                    ) ?? $last,
                    'total' => $messages->count(),
                    'incoming' => $messages->where('from_me', false)->count(),
                    // Минуты рабочего времени; null — на последнее слово
                    // ответили, ждать нечего.
                    'waited' => $wait['waited'] ?? null,
                    'overdue' => (bool) ($wait['overdue'] ?? false),
                    'ever_answered' => (bool) ($wait['ever_answered'] ?? true),
                ];
            })
            ->sortByDesc(fn ($chat) => $chat['last']->sent_at)
            ->values();

        $today = now()->timezone(WhatsappSla::timezone())->startOfDay();
        $counts = [
            'all' => $chats->count(),
            'waiting' => $chats->whereNotNull('waited')->count(),
            'today' => $chats->filter(
                fn ($chat) => $chat['last']->sent_at->timezone(WhatsappSla::timezone())->greaterThanOrEqualTo($today)
            )->count(),
            'new' => $chats->where('ever_answered', false)->count(),
        ];

        // Фильтр — вкладками над списком: «все подряд» отвечает не на тот
        // вопрос, с которого открывают экран.
        $filter = in_array($request->get('filter'), ['waiting', 'today', 'new'], true)
            ? $request->get('filter')
            : 'all';

        $chats = match ($filter) {
            'waiting' => $chats->whereNotNull('waited')->sortByDesc('waited')->values(),
            'today' => $chats->filter(
                fn ($chat) => $chat['last']->sent_at->timezone(WhatsappSla::timezone())->greaterThanOrEqualTo($today)
            )->values(),
            'new' => $chats->where('ever_answered', false)->values(),
            default => $chats,
        };

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
            'waitingCount' => $counts['waiting'],
            'counts' => $counts,
            'filter' => $filter,
            'threshold' => WhatsappSla::threshold(),
        ]);
    }

    /**
     * Переписка для правой колонки списка.
     *
     * Отдаём кусок разметки, а не JSON: сообщения рисует тот же партиал,
     * что и отдельная страница диалога, и расходиться им незачем.
     */
    public function panel(Request $request, string $phone)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $digits = preg_replace('/\D/', '', $phone);
        $messages = WhatsappMessage::where('club_id', $club->id)
            ->where('phone', $digits)
            ->orderBy('sent_at')
            ->get();

        if ($messages->isEmpty()) abort(404);

        $tz = config('app.schedule_timezone', 'Asia/Almaty');

        return view('club.whatsapp.partials._panel', [
            'phone' => $digits,
            'name' => $messages->firstWhere('from_me', false)?->author_name,
            'client' => $this->clientsByPhone($club, [$digits])[substr($digits, -10)] ?? null,
            'days' => $messages->groupBy(fn ($m) => $m->sent_at->timezone($tz)->toDateString()),
            'total' => $messages->count(),
            'waited' => WhatsappSla::waitingChats($club->id)->firstWhere('phone', $digits)['waited'] ?? null,
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
    /** Разбор дня: цифры считаем сами, объяснение спрашиваем у Claude. */
    public function analysis(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $tz = WhatsappSla::timezone();
        $date = $this->analysisDate($request, $tz);

        $analysis = WhatsappAnalysis::where('club_id', $club->id)
            ->whereDate('date', $date)
            ->first();

        // Цифры показываем всегда, даже если разбор ещё не заказывали:
        // по ним уже видно, был ли день проблемным.
        $report = WhatsappDayReport::build($club->id, $date);

        return view('club.whatsapp.analysis', [
            'club' => $club,
            'date' => $date,
            'metrics' => $report['metrics'],
            'hours' => $report['hours'],
            'outside' => $report['hours_outside'],
            'dialogs' => $report['dialogs'],
            'analysis' => $analysis,
            'days' => $this->recentDays($club->id, $tz),
            'people' => $this->peopleByTail($club, $report['dialogs']),
            // Сколько обычно ждать — для обратного отсчёта на экране.
            'estimate' => WhatsappAnalysisService::typicalSeconds($club->id),
        ]);
    }

    /** Заказать разбор у модели. */
    public function runAnalysis(Request $request, WhatsappAnalysisService $service)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $date = $this->analysisDate($request, WhatsappSla::timezone());

        try {
            $analysis = $service->analyze($club->id, $date, $request->boolean('force'), auth()->id());
        } catch (\Throwable $e) {
            // Экран ждёт ответа через fetch и сам покажет ошибку в шторке
            // ожидания: перезагружать страницу ради строчки незачем.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('club.whatsapp.analysis', ['date' => $date->toDateString()])
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'seconds' => (int) round(($analysis->duration_ms ?? 0) / 1000),
            ]);
        }

        return redirect()
            ->route('club.whatsapp.analysis', ['date' => $date->toDateString()])
            ->with('success', 'Разбор готов');
    }

    /**
     * Кто скрывается за «…2677» в разборе.
     *
     * Модель ссылается на диалоги последними четырьмя цифрами номера —
     * иначе разбор занял бы полконтекста. Здесь возвращаем этим хвостам
     * имя и полный номер, чтобы из находки можно было перейти в переписку.
     *
     * Заодно отдаём цифры дня по этому диалогу: в находке они превращают
     * оценку модели в проверяемый факт.
     *
     * @return array<string, array>
     */
    private function peopleByTail(Club $club, array $dialogs): array
    {
        $clients = $this->clientsByPhone($club, array_column($dialogs, 'phone'));

        $map = [];
        foreach ($dialogs as $dialog) {
            $tail = substr((string) $dialog['phone'], -4);
            if (strlen($tail) < 4) {
                continue;
            }

            // Два номера с одним хвостом — угадывать нельзя: лучше
            // показать хвост без имени, чем увести не в тот диалог.
            if (array_key_exists($tail, $map)) {
                $map[$tail] = null;
                continue;
            }

            $client = $clients[substr((string) $dialog['phone'], -10)] ?? null;

            $map[$tail] = [
                'phone' => (string) $dialog['phone'],
                'name' => $client->name ?? $dialog['name'] ?? '',
                'is_client' => (bool) $client,
                'requests' => (int) ($dialog['requests'] ?? 0),
                'unanswered' => (int) ($dialog['unanswered'] ?? 0),
                'worst' => $dialog['worst'] ?? null,
                'is_new' => (bool) ($dialog['is_new'] ?? false),
                'booked' => (bool) ($dialog['booked'] ?? false),
            ];
        }

        return array_filter($map);
    }

    /** Выбранный день или вчерашний: сегодняшний ещё не закончился. */
    private function analysisDate(Request $request, string $tz): Carbon
    {
        $raw = (string) $request->get('date');

        try {
            $date = $raw !== '' ? Carbon::parse($raw, $tz) : now($tz)->subDay();
        } catch (\Throwable) {
            $date = now($tz)->subDay();
        }

        return $date->startOfDay();
    }

    /** Дни, за которые вообще есть переписка — для быстрых кнопок. */
    private function recentDays(int $clubId, string $tz): array
    {
        return WhatsappMessage::where('club_id', $clubId)
            ->where('sent_at', '>=', now()->subDays(14))
            ->orderByDesc('sent_at')
            ->limit(5000)
            ->pluck('sent_at')
            ->map(fn ($at) => $at->timezone($tz)->toDateString())
            ->unique()
            ->take(10)
            ->values()
            ->all();
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
