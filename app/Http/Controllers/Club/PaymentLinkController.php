<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ClubClient;
use App\Models\PaymentLink;
use App\Services\PaymentLinkService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Счета клиентам: админ или менеджер выставляет ссылку на оплату,
 * клиент платит картой.
 */
class PaymentLinkController extends Controller
{
    public function __construct(private PaymentLinkService $links)
    {
    }

    public function index(Request $request)
    {
        $club = $this->club($request);

        $query = PaymentLink::forClub($club->id)
            ->with(['creator', 'client'])
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

        $links = $query->paginate(25)->withQueryString();

        // Сводка за 30 дней — сколько выставлено и сколько реально получено.
        $recent = PaymentLink::forClub($club->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        return view('club.payments.index', [
            'club' => $club,
            'links' => $links,
            'paidSum' => $recent->where('status', PaymentLink::STATUS_PAID)->sum('amount'),
            'paidCount' => $recent->where('status', PaymentLink::STATUS_PAID)->count(),
            'pendingSum' => $recent->where('status', PaymentLink::STATUS_PENDING)->sum('amount'),
            'pendingCount' => $recent->where('status', PaymentLink::STATUS_PENDING)->count(),
        ]);
    }

    /**
     * Вся касса клуба: транзакции прямо из Plexy.
     *
     * Отдельная вкладка, потому что источник другой. Счета из CRM — это то,
     * что клуб выставил сам; здесь же видно всё, за что вообще заплатили:
     * брони и турниры из приложения и ссылки, созданные в кабинете Plexy.
     */
    public function appPayments(Request $request)
    {
        $club = $this->club($request);

        $page = max(1, (int) $request->get('page', 1));
        $error = null;
        $data = ['rows' => [], 'page' => $page, 'size' => 50, 'total' => 0];

        if ($club->hasPlexyConfigured()) {
            try {
                $data = \App\Support\PlexyTransactions::page(
                    $club,
                    $page,
                    50,
                    $request->boolean('refresh')
                );
            } catch (\Throwable $e) {
                // Шлюз может лежать — страница должна открыться и сказать об этом,
                // а не отдать 500.
                $error = $e->getMessage();
            }
        }

        return view('club.payments.app', [
            'club' => $club,
            'rows' => $data['rows'],
            'page' => $data['page'],
            'size' => $data['size'],
            'total' => $data['total'],
            'error' => $error,
        ]);
    }

    /**
     * Подсказка клиентов для формы счёта: один инпут ищет и по имени,
     * и по телефону — менеджеру на ресепшене удобнее вбить то, что помнит.
     * Короче трёх символов не ищем: пол-базы в выпадашке бесполезно.
     */
    public function clients(Request $request)
    {
        $club = $this->club($request);

        $q = trim((string) $request->get('q'));
        if (mb_strlen($q) < 3) {
            return response()->json([]);
        }

        $digits = preg_replace('/\D/', '', $q);

        $clients = ClubClient::where('club_id', $club->id)
            ->where(function ($query) use ($q, $digits) {
                $query->where('name', 'like', "%{$q}%");
                if ($digits !== '') {
                    $query->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') LIKE ?",
                        ["%{$digits}%"]
                    );
                }
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone']);

        // Уважаем настройку клуба «скрывать телефоны» — в подсказке
        // показываем маску, сам номер для WhatsApp берётся из базы.
        $clients->transform(function ($client) {
            $client->phone = \App\Support\PhoneVisibility::forExport($client->phone);
            return $client;
        });

        return response()->json($clients);
    }

    public function store(Request $request)
    {
        $club = $this->club($request);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:10000000',
            'description' => 'required|string|max:200',
            'expires_in_hours' => 'required|integer|in:1,3,24,72,168',
            'club_client_id' => 'nullable|integer',
            'client_name' => 'nullable|string|max:255',
            'client_phone' => 'nullable|string|max:32',
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $link = $this->links->create($club, $request->user(), $validated);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('club.payments.index')
            ->with('success', 'Счёт создан — ссылка готова')
            ->with('new_link_id', $link->id);
    }

    /** Спросить Plexy о состоянии счёта (если вебхук не дошёл). */
    public function sync(Request $request, PaymentLink $link)
    {
        $this->assertOwn($request, $link);

        $changed = $this->links->sync($link);

        return back()->with(
            'success',
            $changed ? 'Статус обновлён: ' . $link->fresh()->statusLabel() : 'Изменений нет'
        );
    }

    /**
     * Обновить статусы всех ожидающих счетов разом — чтобы не жать
     * «Проверить» у каждого по очереди.
     */
    public function syncAll(Request $request)
    {
        $club = $this->club($request);

        $pending = PaymentLink::forClub($club->id)
            ->where('status', PaymentLink::STATUS_PENDING)
            ->whereNotNull('plexy_link_id')
            ->with('club')
            ->get();

        $changed = 0;
        foreach ($pending as $link) {
            if ($this->links->sync($link)) {
                $changed++;
            }
        }

        return back()->with('success', $changed
            ? "Обновлено счетов: {$changed}"
            : 'Проверено ' . $pending->count() . ' — изменений нет');
    }

    public function cancel(Request $request, PaymentLink $link)
    {
        $this->assertOwn($request, $link);

        try {
            $this->links->cancel($link);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Счёт отменён');
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

    /** Счёт чужого клуба трогать нельзя. */
    private function assertOwn(Request $request, PaymentLink $link): void
    {
        $club = $this->club($request);
        abort_unless((int) $link->club_id === (int) $club->id, 403, 'Счёт другого клуба');
    }
}
