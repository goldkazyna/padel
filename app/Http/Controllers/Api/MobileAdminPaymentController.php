<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubClient;
use App\Models\PaymentLink;
use App\Services\PaymentLinkService;
use App\Services\PlexyRefundService;
use App\Support\ClubTime;
use App\Support\PlexyTransactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Платежи клуба с телефона: выставить счёт, посмотреть кассу, вернуть деньги.
 *
 * То же, что в веб-кабинете (`/club/payments`), только для мобильной админки —
 * администратор на корте не должен бежать к компьютеру, чтобы выставить счёт
 * или проверить, дошли ли деньги.
 */
class MobileAdminPaymentController extends Controller
{
    public function __construct(private PaymentLinkService $links)
    {
    }

    /**
     * GET /api/mobile/admin/payments
     * Счета клуба: список, сводка и фильтр по статусу.
     */
    public function index(Request $request): JsonResponse
    {
        $club = $this->club($request);

        $query = PaymentLink::forClub($club->id)->with(['creator', 'client'])
            ->orderByDesc('created_at');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->get('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('client_phone', 'like', "%{$search}%");
            });
        }

        $links = $query->limit(100)->get();

        return response()->json([
            'success' => true,
            'can_refund' => $this->isClubAdmin($request, $club->id),
            'summary' => [
                'paid_sum' => (float) PaymentLink::forClub($club->id)
                    ->where('status', PaymentLink::STATUS_PAID)->sum('amount'),
                'paid_count' => PaymentLink::forClub($club->id)
                    ->where('status', PaymentLink::STATUS_PAID)->count(),
                'pending_count' => PaymentLink::forClub($club->id)
                    ->where('status', PaymentLink::STATUS_PENDING)->count(),
            ],
            'links' => $links->map(fn ($l) => $this->formatLink($l))->all(),
        ]);
    }

    /**
     * POST /api/mobile/admin/payments
     * Выставить счёт: возвращает готовую ссылку на оплату.
     */
    public function store(Request $request): JsonResponse
    {
        $club = $this->club($request);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:10000000',
            'description' => 'required|string|max:200',
            'expires_in_hours' => 'nullable|integer|in:1,3,24,72,168',
            'club_client_id' => 'nullable|integer',
            'client_name' => 'nullable|string|max:255',
            'client_phone' => 'nullable|string|max:32',
            'note' => 'nullable|string|max:1000',
        ]);

        // Срок по умолчанию — сутки, как в вебе: столько живёт ссылка,
        // если клуб не выбрал другой.
        $validated['expires_in_hours'] ??= 24;

        try {
            $link = $this->links->create($club, $request->user(), $validated);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'link' => $this->formatLink($link->fresh(['creator', 'client'])),
        ]);
    }

    /**
     * POST /api/mobile/admin/payments/{link}/sync
     * Спросить шлюз о состоянии счёта: вебхуки до нас не доходят.
     */
    public function sync(Request $request, PaymentLink $link): JsonResponse
    {
        $this->assertOwn($request, $link);

        $changed = $this->links->sync($link);

        return response()->json([
            'success' => true,
            'changed' => $changed,
            'link' => $this->formatLink($link->fresh(['creator', 'client'])),
        ]);
    }

    /**
     * DELETE /api/mobile/admin/payments/{link}
     * Отменить счёт — ссылка перестанет открываться.
     */
    public function cancel(Request $request, PaymentLink $link): JsonResponse
    {
        $this->assertOwn($request, $link);

        try {
            $this->links->cancel($link);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'link' => $this->formatLink($link->fresh(['creator', 'client'])),
        ]);
    }

    /**
     * GET /api/mobile/admin/payments/transactions
     * Касса целиком: все платежи Plexy с расшифровкой, за что платили.
     */
    public function transactions(Request $request): JsonResponse
    {
        $club = $this->club($request);

        if (!$club->hasPlexyConfigured()) {
            return response()->json([
                'success' => true,
                'rows' => [], 'page' => 1, 'total' => 0,
                'message' => 'У клуба не настроена онлайн-оплата',
            ]);
        }

        $page = max(1, (int) $request->get('page', 1));

        try {
            $data = PlexyTransactions::page($club, $page, 50, $request->boolean('refresh'));
        } catch (\Throwable $e) {
            // Шлюз может лежать — экран должен открыться и сказать об этом.
            return response()->json([
                'success' => true,
                'rows' => [], 'page' => $page, 'total' => 0,
                'message' => 'Шлюз не отвечает: ' . $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'can_refund' => $this->isClubAdmin($request, $club->id),
            'page' => $data['page'],
            'total' => $data['total'],
            'rows' => collect($data['rows'])->map(fn ($r) => [
                'id' => $r['id'],
                'rrn' => $r['rrn'],
                'amount' => (float) $r['amount'],
                'status' => $r['status'],
                'created_at' => ClubTime::iso($r['created_at']),
                'kind' => $r['kind'],
                'title' => $r['title'],
                'subtitle' => $r['subtitle'],
            ])->all(),
        ]);
    }

    /**
     * POST /api/mobile/admin/payments/transactions/{transaction}/refund
     * Возврат денег или снятие холда — то же действие, что в вебе.
     */
    public function refund(Request $request, string $transaction, PlexyRefundService $refunds): JsonResponse
    {
        $club = $this->club($request);

        if (!$this->isClubAdmin($request, $club->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Возврат делает администратор клуба',
            ], 403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $result = $refunds->refund(
                $club,
                $transaction,
                (float) $validated['amount'],
                $validated['reason'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => $result['message']]);
    }

    /**
     * GET /api/mobile/admin/payments/clients?q=
     * Подсказка клиентов для формы счёта: ищем и по имени, и по телефону.
     */
    public function clients(Request $request): JsonResponse
    {
        $club = $this->club($request);
        $q = trim((string) $request->get('q'));

        if (mb_strlen($q) < 3) {
            return response()->json(['success' => true, 'clients' => []]);
        }

        $clients = ClubClient::where('club_id', $club->id)
            ->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone']);

        return response()->json(['success' => true, 'clients' => $clients]);
    }

    /** @return array<string, mixed> */
    private function formatLink(PaymentLink $link): array
    {
        return [
            'id' => $link->id,
            'amount' => (float) $link->amount,
            'description' => $link->description,
            'status' => $link->status,
            'status_label' => $link->statusLabel(),
            'client_name' => $link->client_name,
            'client_phone' => $link->client_phone,
            'url' => $link->plexy_url,
            'note' => $link->note,
            'author' => $link->creator?->name,
            'created_at' => ClubTime::iso($link->created_at),
            'expires_at' => ClubTime::iso($link->expires_at),
            'paid_at' => ClubTime::iso($link->paid_at),
        ];
    }

    /** Клуб текущего пользователя; менеджеру доступен его клуб. */
    private function club(Request $request)
    {
        $user = $request->user();

        $club = $user->isSuperAdmin()
            ? \App\Models\Club::query()->first()
            : ($user->isClubModerator()
                ? $user->moderatorClubs()->first()
                : $user->adminClubs()->first());

        abort_unless($club, 403, 'Вы не привязаны к клубу');

        return $club;
    }

    /** Возврат — деньги наружу: только администратор, не менеджер. */
    private function isClubAdmin(Request $request, int $clubId): bool
    {
        $user = $request->user();

        return $user->isSuperAdmin()
            || $user->adminClubs()->where('clubs.id', $clubId)->exists();
    }

    /** Счёт чужого клуба трогать нельзя. */
    private function assertOwn(Request $request, PaymentLink $link): void
    {
        abort_unless($link->club_id === $this->club($request)->id, 403);
    }
}
