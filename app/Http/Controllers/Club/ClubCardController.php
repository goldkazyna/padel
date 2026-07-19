<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubCard;
use App\Models\ClubCardType;
use App\Models\ClubClient;
use App\Models\CourtBooking;
use App\Services\ClubCardService;
use Illuminate\Http\Request;

class ClubCardController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    /** Выпустить карту клиенту. */
    public function store(Request $request, ClubCardService $service)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $data = $request->validate([
            'club_client_id' => 'required|integer',
            'club_card_type_id' => 'required|integer',
            'balance' => 'nullable|integer|min:0|max:100000',
            'expires_at' => 'nullable|date|after:today',
        ]);

        $client = ClubClient::where('club_id', $club->id)->findOrFail($data['club_client_id']);
        $type = ClubCardType::where('club_id', $club->id)->findOrFail($data['club_card_type_id']);

        $service->issue(
            $client,
            $type,
            $data['balance'] ?? null,
            $data['expires_at'] ?? null
        );

        return back()->with('success', 'Карта «' . $type->name . '» привязана клиенту');
    }

    /**
     * Актуальные карты клиента по телефону — для окна брони корта.
     * GET /club/cards/for-client?phone=...
     */
    public function forClient(Request $request, \App\Services\ClubCardService $cardService)
    {
        $club = $this->getClub();
        if (!$club) return response()->json(['cards' => []]);

        $digits = preg_replace('/\D/', '', (string) $request->get('phone'));
        if (strlen($digits) < 5) return response()->json(['cards' => []]);
        $last10 = substr($digits, -10);

        $client = ClubClient::where('club_id', $club->id)
            ->where(fn($q) => $q->where('phone', $digits)->orWhere('phone', 'like', '%' . $last10))
            ->first();
        if (!$client) return response()->json(['cards' => []]);

        $allCards = ClubCard::where('club_client_id', $client->id)
            ->where('club_id', $club->id)
            ->with('type')
            ->orderByDesc('created_at')
            ->get();

        // При редактировании — исключаем саму бронь из резерва, чтобы её карта
        // не показывалась как «нет свободных часов» из-за неё же самой.
        $excludeBookingId = $request->get('exclude_booking_id');
        $excludeBookingId = $excludeBookingId !== null ? (int) $excludeBookingId : null;

        $mapCard = fn($c, bool $inactive) => [
            'id' => $c->id,
            'code' => $c->code,
            'type_name' => $c->type?->name,
            'kind' => $c->type?->kind,
            'is_counter' => $c->isCounter(),
            'is_discount' => (bool) $c->type?->isDiscount(),
            'balance' => $c->balance,
            // Доступный остаток с учётом ещё не списанных броней (резерв).
            'available' => $c->isCounter() ? $cardService->availableBalance($c, $excludeBookingId) : null,
            'discount_percent' => $c->type?->discount_percent,
            'price' => $c->type?->price,          // стоимость карты
            'nominal' => $c->type?->nominal,      // число занятий типа (для цены за визит)
            // Реальная ёмкость именно этой карты (могли выпустить не на полный номинал).
            // Для отображения «осталось X/Y» знаменатель = ёмкость карты, а не номинал типа.
            'capacity' => $c->initial_balance ?? $c->type?->nominal,
            'inactive' => $inactive,
        ];

        $cards = $allCards->filter->isActual()
            ->map(fn($c) => $mapCard($c, false))
            ->values();

        // Уже привязанная к брони карта могла быть потрачена (balance=0 → isActual()=false).
        // Если её явно запрашивают по id — добавляем в список, чтобы форма редактирования
        // брони могла её показать/предвыбрать, помечая как неактивную.
        $includeCardId = $request->get('include_card_id');
        if ($includeCardId !== null && !$cards->contains('id', (int) $includeCardId)) {
            $extra = $allCards->firstWhere('id', (int) $includeCardId);
            if ($extra) {
                $cards = $cards->push($mapCard($extra, true))->values();
            }
        }

        return response()->json(['cards' => $cards]);
    }

    /** id типа карты → CSS-класс цвета (единый порядок по имени, как на странице карт). */
    private function typeColorMap($club): array
    {
        $palette = ['t-blue', 't-amber', 't-purple', 't-green', 't-pink', 't-cyan'];
        $map = [];
        $types = ClubCardType::where('club_id', $club->id)->orderBy('name')->get();
        foreach ($types->values() as $i => $t) {
            $map[$t->id] = $palette[$i % count($palette)];
        }
        return $map;
    }

    /** Журнал клубных карт — история списаний часов клуба. */
    public function journal()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $typeCls = $this->typeColorMap($club);
        $courts = $club->courts()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);

        $txns = \App\Models\ClubCardTransaction::where('club_id', $club->id)
            ->where('amount', '<', 0) // журнал списаний — только фактические вычеты часов
            ->with(['card.client', 'card.type', 'booking.court'])
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get();

        $rows = $txns->map(function ($t) use ($typeCls) {
            $card = $t->card;
            $b = $t->booking;
            $tname = $card?->type?->name;
            $booking = '—';
            if ($b) {
                $d = \Illuminate\Support\Carbon::parse($b->date)->format('d.m.Y');
                $booking = $d . ', ' . substr((string) $b->start_time, 0, 5) . '–' . substr((string) $b->end_time, 0, 5);
            }
            return [
                'date' => $t->created_at->timezone(config('app.schedule_timezone', 'Asia/Almaty'))->format('d.m.Y H:i'),
                'ts' => $t->created_at->timestamp,
                'name' => $card?->client?->name ?? '—',
                'code' => $card?->code ?? '',
                'tag' => $tname ? mb_substr(trim(explode(' ', $tname)[0]), 0, 14) : '',
                'cls' => $typeCls[$card?->club_card_type_id] ?? 't-purple',
                'court_id' => $b?->court_id,
                'court' => $b?->court?->name ?? '—',
                'booking' => $booking,
                'hours' => abs((int) $t->amount),
                'after' => (int) $t->balance_after,
            ];
        })->values();

        return view('club.cards.journal', compact('club', 'courts', 'rows'));
    }

    /** История одной карты (списания/операции) — для выезжающей панели. */
    public function history(ClubCard $card)
    {
        $club = $this->getClub();
        if (!$club || $card->club_id !== $club->id) abort(403);

        $card->load(['type', 'client', 'transactions.booking.court']);

        return view('club.cards.card_history', [
            'club' => $club,
            'card' => $card,
            '__layout' => request()->boolean('bare') ? 'layouts.bare' : 'layouts.app',
        ]);
    }

    /** Изменить дату окончания карты. Пусто = бессрочно. Любая дата (в т.ч. прошлая). */
    public function updateExpiry(Request $request, ClubCard $card)
    {
        $club = $this->getClub();
        if (!$club || $card->club_id !== $club->id) abort(403);

        $data = $request->validate([
            'expires_at' => 'nullable|date',
        ]);

        $card->update(['expires_at' => $data['expires_at'] ?? null]);

        return back()->with('success', 'Дата окончания обновлена');
    }

    /** Очередь броней к ручному списанию с карты. */
    public function pending(ClubCardService $service)
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $bookings = $service->pendingForClub($club);
        $typeCls = $this->typeColorMap($club);

        return view('club.cards.pending', compact('club', 'bookings', 'typeCls'));
    }

    /** С какой даты считаем «не выставленные карты». */
    public const UNLINKED_SINCE = '2026-06-15';

    /** Брони с оплатой «клубная карта», но без привязанной карты (с UNLINKED_SINCE). */
    public function unlinked()
    {
        $club = $this->getClub();
        if (!$club) abort(403);

        $bookings = CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('payment_method', 'club_card')
            ->whereNull('club_card_id')
            ->where('status', 'confirmed')
            ->whereDate('date', '>=', self::UNLINKED_SINCE)
            ->with('court:id,name')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return view('club.cards.unlinked', compact('club', 'bookings'));
    }

    /** Списать часы карты за бронь (ручное действие). */
    public function charge(CourtBooking $booking, ClubCardService $service)
    {
        $club = $this->getClub();
        $this->authorizeBooking($club, $booking);

        $service->chargeBooking($booking);

        return back()->with('success', 'Списано с карты');
    }

    /** Пометить бронь обработанной без списания. */
    public function skip(CourtBooking $booking, ClubCardService $service)
    {
        $club = $this->getClub();
        $this->authorizeBooking($club, $booking);

        $service->skipBooking($booking);

        return back()->with('success', 'Бронь помечена без списания');
    }

    /** Бронь должна принадлежать корту своего клуба. */
    private function authorizeBooking($club, CourtBooking $booking): void
    {
        if (!$club) abort(403);
        $courtIds = $club->courts()->pluck('id')->all();
        if (!in_array($booking->court_id, $courtIds, true)) abort(403);
    }

    /** Отвязать (удалить) карту клиента. */
    public function destroy(ClubCard $card)
    {
        $club = $this->getClub();
        if (!$club || $card->club_id !== $club->id) abort(403);

        // Нельзя удалять карты с историей: по ней хоть раз списывали часы —
        // удаление стёрло бы списания и испортило отчёты/журнал.
        if ($card->transactions()->exists()) {
            return back()->with('error', 'Нельзя удалить карту: по ней есть списания (история и отчёты должны остаться).');
        }

        // Нельзя удалять использованные карты-счётчики (часы кончились).
        if ($card->isCounter() && (int) $card->balance <= 0) {
            return back()->with('error', 'Нельзя удалить использованную карту — часы кончились.');
        }

        $card->delete();

        return back()->with('success', 'Карта удалена');
    }
}
