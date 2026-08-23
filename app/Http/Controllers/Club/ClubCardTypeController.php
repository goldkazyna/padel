<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubCardType;
use Illuminate\Http\Request;

class ClubCardTypeController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    public function index(\App\Services\ClubCardService $cardService)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $types = ClubCardType::where('club_id', $club->id)
            ->orderBy('name')
            ->get();

        // Статистика по выпущенным картам.
        $issuedCount = \App\Models\ClubCard::where('club_id', $club->id)->count();

        $today = now()->startOfDay();
        $todayStr = $today->toDateString();
        $soonEdge = $today->copy()->addDays(7); // «истекает» — в ближайшие 7 дней

        // «Актуально сейчас»: активна, не истекла, у счётчиков остаток > 0.
        $actualCount = \App\Models\ClubCard::where('club_id', $club->id)
            ->where('status', 'active')
            ->where(fn($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $todayStr))
            ->where(fn($q) => $q->whereNull('balance')->orWhere('balance', '>', 0))
            ->count();

        $allCards = \App\Models\ClubCard::where('club_id', $club->id)
            ->with(['type', 'client'])
            ->withCount('transactions')
            ->get();

        // Цвет-класс и короткий тег для каждого типа.
        $palette = ['t-blue', 't-amber', 't-purple', 't-green', 't-pink', 't-cyan'];
        $typeMeta = [];
        foreach ($types->values() as $i => $t) {
            $typeMeta[$t->id] = [
                'cls' => $palette[$i % count($palette)],
                'tag' => mb_substr(trim(explode(' ', (string) $t->name)[0] ?? $t->name), 0, 14),
            ];
            $t->ui_cls = $typeMeta[$t->id]['cls'];
        }

        // Карты для JS-списка: только карты существующих типов, с вычисленным статусом.
        $counts = ['all' => 0, 'active' => 0, 'soon' => 0, 'inactive' => 0, 'perp' => 0, 'used' => 0];
        $cardsData = [];
        foreach ($allCards as $c) {
            if (!$c->type) continue;
            $st = $this->cardUiStatus($c, $today, $soonEdge);
            $counter = $c->isCounter();
            $bal = (int) $c->balance;
            // Удалять можно только карты без истории списаний и не использованные.
            $canDelete = $c->transactions_count === 0 && !($counter && $bal <= 0);
            $counts['all']++;
            $counts[$st]++;
            $cardsData[] = [
                'type_id' => (int) $c->club_card_type_id,
                'name' => $c->client?->name ?? '— клиент удалён —',
                'code' => (string) $c->code,
                'bal' => $bal,
                'init' => (int) $c->initial_balance,
                'counter' => $counter,
                'discount' => $counter ? null : (int) ($c->type->discount_percent ?? 0),
                'date' => $c->expires_at ? $c->expires_at->locale('ru')->translatedFormat('j M Y') : 'бессрочно',
                'issued' => $c->created_at ? $c->created_at->timezone(config('app.schedule_timezone', 'Asia/Almaty'))->locale('ru')->translatedFormat('j M Y') : '—',
                'exp' => $c->expires_at ? $c->expires_at->timestamp : PHP_INT_MAX,
                'st' => $st,
                'low' => $counter && $st !== 'inactive' && $st !== 'used' && $bal > 0 && $bal <= 4,
                'url' => $c->client ? route('club.clients.index', ['selected' => $c->client->id]) : null,
                'del' => $canDelete ? route('club.cards.destroy', $c->id) : null,
            ];
        }

        // Данные типов для JS (название/часы/цвет/агрегаты).
        $byType = collect($cardsData)->groupBy('type_id');
        $typesData = [];
        foreach ($types as $t) {
            $g = $byType[$t->id] ?? collect();
            $typesData[] = [
                'id' => (int) $t->id,
                'name' => (string) $t->name,
                'desc' => (string) ($t->description ?? ''),
                'hours' => $t->isCounter() ? (int) $t->nominal : null,
                'cls' => $typeMeta[$t->id]['cls'],
                'tag' => $typeMeta[$t->id]['tag'],
                'total' => $g->count(),
                'active' => $g->whereIn('st', ['active', 'soon', 'perp'])->count(),
                'oborot' => (int) $g->where('st', '!=', 'inactive')->sum('bal'),
            ];
            $t->ui_count = $g->count();
        }

        $pendingChargeCount = $cardService->pendingCountForClub($club);

        // Брони с оплатой «клубная карта», но без привязанной карты (с UNLINKED_SINCE).
        $unlinkedCount = \App\Models\CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('payment_method', 'club_card')
            ->whereNull('club_card_id')
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', \App\Http\Controllers\Club\ClubCardController::UNLINKED_SINCE)
            ->count();

        return view('club.cards.index', compact(
            'club', 'types', 'issuedCount', 'actualCount', 'pendingChargeCount', 'unlinkedCount',
            'cardsData', 'typesData', 'counts'
        ));
    }

    /**
     * UI-статус выпущенной карты:
     * inactive — снята/истекла; perp — без срока; soon — истекает ≤7 дней; active — иначе.
     */
    private function cardUiStatus(\App\Models\ClubCard $card, \Carbon\Carbon $today, \Carbon\Carbon $soonEdge): string
    {
        if ($card->status !== 'active') return 'inactive';
        // Счётчик с нулевым остатком — карта использована (часы кончились).
        if ($card->isCounter() && (int) $card->balance <= 0) return 'used';
        if ($card->expires_at === null) return 'perp';
        $exp = $card->expires_at->copy()->startOfDay();
        if ($exp->lt($today)) return 'inactive';
        if ($exp->lte($soonEdge)) return 'soon';
        return 'active';
    }

    public function store(Request $request)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $data = $this->validateType($request, $club);
        $data['club_id'] = $club->id;
        $this->normalizeByKind($data);
        $this->normalizeValidity($data);

        ClubCardType::create($data);

        return back()->with('success', 'Тип карты создан');
    }

    public function update(Request $request, ClubCardType $cardType)
    {
        $club = $this->getClub();
        if (!$club || $cardType->club_id !== $club->id) abort(403);

        // По типу уже выпущены карты — менять можно ТОЛЬКО срок действия.
        // Срок применяется лишь к новым картам: у выпущенных дата зафиксирована
        // при выдаче и не перечитывается. Вид/номинал/цена/префикс заблокированы —
        // их изменение сломало бы смысл уже выданных карт.
        $issued = $cardType->cards()->count();
        if ($issued > 0) {
            // Описание — просто текст для владельца карты, менять его безопасно
            // и после выдачи: условия клуба со временем уточняются.
            $data = $request->validate([
                'description' => 'nullable|string|max:2000',
                'validity_mode' => 'nullable|in:forever,date,days',
                'default_expires_at' => 'nullable|date|after:today',
                'default_validity_days' => 'nullable|integer|min:1|max:3650',
            ]);
            $this->normalizeValidity($data);
            $cardType->update($data);

            return back()->with('success', 'Описание и срок действия обновлены. Уже выпущенные карты сохранят свой срок — новый применится к новым картам.');
        }

        $data = $this->validateType($request, $club, $cardType->id);
        $this->normalizeByKind($data);
        $this->normalizeValidity($data);

        $cardType->update($data);

        return back()->with('success', 'Тип карты обновлён');
    }

    public function destroy(ClubCardType $cardType)
    {
        $club = $this->getClub();
        if (!$club || $cardType->club_id !== $club->id) abort(403);

        // Нельзя удалить тип, пока есть активные выпущенные карты этого типа.
        $active = $cardType->cards()->where('status', 'active')->count();
        if ($active > 0) {
            return back()->with('error', 'Сначала отвяжите карты этого типа от клиентов (активных: ' . $active . ')');
        }

        $cardType->delete();

        return back()->with('success', 'Тип карты удалён');
    }

    private function validateType(Request $request, $club, ?int $ignoreId = null): array
    {
        // Префикс нормализуем в верхний регистр без пробелов до проверки уникальности.
        $request->merge([
            'code_prefix' => strtoupper(trim((string) $request->input('code_prefix'))),
        ]);

        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'code_prefix' => [
                'required', 'string', 'max:12', 'regex:/^[A-Z0-9]+$/',
                \Illuminate\Validation\Rule::unique('club_card_types', 'code_prefix')
                    ->where('club_id', $club->id)
                    ->ignore($ignoreId),
            ],
            'kind' => 'required|in:visits,trainer,discount_court,discount_trainer',
            'nominal' => 'nullable|integer|min:1|max:10000',
            'discount_percent' => 'nullable|integer|min:1|max:100',
            'price' => 'nullable|integer|min:0|max:100000000',
            'validity_mode' => 'nullable|in:forever,date,days',
            'default_expires_at' => 'nullable|date|after:today',
            'default_validity_days' => 'nullable|integer|min:1|max:3650',
        ], [
            'code_prefix.regex' => 'Префикс — только латинские буквы и цифры (напр. VIP).',
            'code_prefix.unique' => 'Такой префикс уже используется другим типом карты.',
        ]);
    }

    /** Очищаем неактуальные для вида поля. */
    private function normalizeByKind(array &$data): void
    {
        $isCounter = in_array($data['kind'], ['visits', 'trainer'], true);
        if ($isCounter) {
            $data['discount_percent'] = null;
            $data['nominal'] = $data['nominal'] ?? 1;
        } else {
            $data['nominal'] = null;
            $data['discount_percent'] = $data['discount_percent'] ?? 1;
        }
    }

    /** Срок действия: forever — оба null; date — фикс. дата; days — N дней с выдачи. */
    private function normalizeValidity(array &$data): void
    {
        $mode = $data['validity_mode'] ?? 'forever';
        unset($data['validity_mode']); // не колонка, только хелпер формы

        if ($mode === 'date') {
            $data['default_validity_days'] = null;
        } elseif ($mode === 'days') {
            $data['default_expires_at'] = null;
        } else {
            $data['default_expires_at'] = null;
            $data['default_validity_days'] = null;
        }
    }
}
