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
            ->withCount(['cards as active_cards_count' => fn($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();

        // Статистика по выпущенным картам.
        $issuedCount = \App\Models\ClubCard::where('club_id', $club->id)->count();

        // «Актуально сейчас»: активна, не истекла, у счётчиков остаток > 0.
        $today = now()->toDateString();
        $actualCount = \App\Models\ClubCard::where('club_id', $club->id)
            ->where('status', 'active')
            ->where(fn($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today))
            ->where(fn($q) => $q->whereNull('balance')->orWhere('balance', '>', 0))
            ->count();

        // Список выпущенных карт (последние) с клиентом и типом.
        $issuedCards = \App\Models\ClubCard::where('club_id', $club->id)
            ->with(['type', 'client'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $pendingChargeCount = $cardService->pendingCountForClub($club);

        return view('club.cards.index', compact('club', 'types', 'issuedCount', 'actualCount', 'issuedCards', 'pendingChargeCount'));
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
