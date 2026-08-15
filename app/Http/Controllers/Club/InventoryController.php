<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ClubClient;
use App\Models\ClubInventoryIssue;
use App\Models\ClubInventoryIssueItem;
use App\Models\ClubInventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Справочник инвентаря клуба: аренда ракеток, мячи и прочее платное,
 * не связанное с кортами. Пока только справочник — без остатков и продаж.
 */
class InventoryController extends Controller
{
    /** Клуб текущего пользователя — как в остальных разделах клуба. */
    private function getClub()
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) return \App\Models\Club::first();
        if ($user->isClubModerator()) return $user->moderatorClubs()->first();
        return $user->adminClubs()->first();
    }

    /**
     * Клуб текущего пользователя, иначе 403.
     * Модуль `inventory` проверяет middleware `club.feature:inventory` на маршрутах —
     * как во всех остальных разделах клуба (он же пропускает супер-админа).
     */
    private function requireClub()
    {
        $club = $this->getClub();
        if (!$club) abort(403, 'У вас нет клуба');

        return $club;
    }

    /** Правила общие для добавления и редактирования. Цена — целые тенге, без копеек. */
    private static function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    /** Русские тексты ошибок валидации. */
    private static function messages(): array
    {
        return [
            'name.required' => 'Укажите название позиции',
            'name.max' => 'Название слишком длинное (максимум :max символов)',
            'price.required' => 'Укажите цену',
            'price.integer' => 'Цена должна быть целым числом тенге, без копеек',
            'price.min' => 'Цена не может быть отрицательной',
        ];
    }

    public function index()
    {
        $club = $this->requireClub();

        $items = ClubInventoryItem::where('club_id', $club->id)
            ->orderBy('name')
            ->get();

        // Красный бейдж на плитке: сколько единиц этой позиции сейчас на руках.
        $outByItem = $this->outstandingByItem($club->id);

        // Карточки раздела «Выданный инвентарь» — по клиенту, а не по выдаче:
        // человек мог брать в разное время, а на ресепшене нужен один список.
        $holders = $this->holders($club->id);

        // Справочник для формы выдачи — только то, что можно выдать.
        $issuable = $items->where('is_active', true)->values();

        return view('club.inventory.index', compact('club', 'items', 'outByItem', 'holders', 'issuable'));
    }

    /**
     * Сколько единиц каждой позиции сейчас не вернули.
     *
     * @return \Illuminate\Support\Collection id позиции => количество
     */
    private function outstandingByItem(int $clubId)
    {
        return ClubInventoryIssueItem::query()
            ->join('club_inventory_issues as i', 'i.id', '=', 'club_inventory_issue_items.club_inventory_issue_id')
            ->where('i.club_id', $clubId)
            ->whereNull('club_inventory_issue_items.returned_at')
            ->whereNotNull('club_inventory_issue_items.club_inventory_item_id')
            ->groupBy('club_inventory_issue_items.club_inventory_item_id')
            ->selectRaw('club_inventory_issue_items.club_inventory_item_id as item_id, SUM(quantity) as qty')
            ->pluck('qty', 'item_id');
    }

    /**
     * Клиенты, за которыми что-то числится. Сверху те, у кого висит дольше всех —
     * их и нужно дёргать в первую очередь.
     *
     * @return \Illuminate\Support\Collection
     */
    private function holders(int $clubId)
    {
        $issues = ClubInventoryIssue::where('club_id', $clubId)
            ->whereHas('items', fn ($q) => $q->whereNull('returned_at'))
            ->with(['client', 'openItems'])
            ->get();

        return $issues
            ->groupBy('club_client_id')
            ->map(function ($clientIssues) {
                $lines = $clientIssues->flatMap(fn ($issue) => $issue->openItems)->values();

                // Точка отсчёта — самая старая невозвращённая выдача клиента.
                $since = $clientIssues->min('created_at');

                return [
                    'client' => $clientIssues->first()->client,
                    'lines' => $lines,
                    'since' => $since,
                    'age' => self::humanAge($since),
                    // Сутки на руках — повод напомнить. Такие карточки идут с жёлтой меткой.
                    'late' => $since && $since->diffInHours(now()) >= 24,
                    'units' => $lines->sum('quantity'),
                ];
            })
            ->sortBy('since')
            ->values();
    }

    /**
     * Сколько прошло, словами: «40 минут», «3 часа», «2 дня».
     * Своя реализация, потому что локаль приложения английская, а Carbon
     * отдал бы «2 days» прямо в интерфейс клуба.
     */
    private static function humanAge($since): string
    {
        if (!$since) return '';

        // Carbon отдаёт дробное число минут — округляем вниз, иначе в интерфейс
        // уедет «40.686060983333 минут».
        $minutes = max(0, (int) floor($since->diffInMinutes(now())));

        if ($minutes < 60) {
            return $minutes . ' ' . self::plural($minutes, 'минуту', 'минуты', 'минут');
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours . ' ' . self::plural($hours, 'час', 'часа', 'часов');
        }

        $days = intdiv($hours, 24);

        return $days . ' ' . self::plural($days, 'день', 'дня', 'дней');
    }

    /** Русское склонение по числу. */
    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        if ($mod100 >= 11 && $mod100 <= 14) return $many;

        return match ($n % 10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }

    /** Выдать инвентарь клиенту: одна выдача, в ней сколько угодно позиций. */
    public function issue(Request $request)
    {
        $club = $this->requireClub();

        $data = $request->validate([
            'club_client_id' => 'required|integer',
            'comment' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
        ], [
            'club_client_id.required' => 'Выберите клиента',
            'items.required' => 'Добавьте хотя бы одну позицию',
            'items.min' => 'Добавьте хотя бы одну позицию',
            'items.*.quantity.min' => 'Количество должно быть не меньше единицы',
            'items.*.quantity.max' => 'Слишком большое количество',
        ]);

        $client = ClubClient::where('club_id', $club->id)
            ->where('id', $data['club_client_id'])
            ->first();
        if (!$client) {
            return back()->with('error', 'Клиент не найден в этом клубе');
        }

        // Позиции берём только свои и только активные — чужой id из формы не пройдёт.
        $catalogue = ClubInventoryItem::where('club_id', $club->id)
            ->where('is_active', true)
            ->whereIn('id', collect($data['items'])->pluck('id'))
            ->get()
            ->keyBy('id');

        if ($catalogue->isEmpty()) {
            return back()->with('error', 'Не удалось выдать: позиции не найдены среди активных');
        }

        $issue = null;
        DB::transaction(function () use ($club, $client, $data, $catalogue, &$issue) {
            $issue = ClubInventoryIssue::create([
                'club_id' => $club->id,
                'club_client_id' => $client->id,
                'issued_by' => auth()->id(),
                'comment' => $data['comment'] ?? null,
            ]);

            // Одну позицию могли добавить в форму дважды — складываем количества,
            // иначе в карточке будут две одинаковые строки.
            $merged = [];
            foreach ($data['items'] as $row) {
                $item = $catalogue->get($row['id']);
                if (!$item) continue;
                $merged[$item->id] = ($merged[$item->id] ?? 0) + (int) $row['quantity'];
            }

            foreach ($merged as $itemId => $quantity) {
                $item = $catalogue->get($itemId);
                $issue->items()->create([
                    'club_inventory_item_id' => $item->id,
                    'name' => $item->name,
                    'price' => (int) $item->price,
                    'quantity' => $quantity,
                ]);
            }
        });

        $names = $issue->items->map(fn ($l) => "{$l->name} ×{$l->quantity}")->implode(', ');
        ActivityLog::log('created', 'ClubInventoryIssue', $issue->id,
            "Инвентарь: выдано клиенту «{$client->name}» — {$names}", clubId: $club->id);

        return back()->with('success', 'Инвентарь выдан');
    }

    /** Принять одну строку выдачи. */
    public function returnItem(ClubInventoryIssueItem $issueItem)
    {
        $club = $this->requireClub();
        $issue = $issueItem->issue;
        if (!$issue || $issue->club_id !== $club->id) abort(403);

        if ($issueItem->isReturned()) {
            return back()->with('error', 'Эта позиция уже принята');
        }

        $issueItem->update(['returned_at' => now(), 'returned_by' => auth()->id()]);

        ActivityLog::log('updated', 'ClubInventoryIssue', $issue->id,
            "Инвентарь: принято от «{$issue->client?->name}» — {$issueItem->name} ×{$issueItem->quantity}",
            clubId: $club->id);

        return back()->with('success', 'Позиция принята');
    }

    /** Принять всё, что числится за клиентом. */
    public function returnClient(ClubClient $client)
    {
        $club = $this->requireClub();
        if ($client->club_id !== $club->id) abort(403);

        $lines = ClubInventoryIssueItem::query()
            ->whereNull('returned_at')
            ->whereHas('issue', fn ($q) => $q->where('club_id', $club->id)->where('club_client_id', $client->id))
            ->get();

        if ($lines->isEmpty()) {
            return back()->with('error', 'За клиентом ничего не числится');
        }

        DB::transaction(function () use ($lines) {
            foreach ($lines as $line) {
                $line->update(['returned_at' => now(), 'returned_by' => auth()->id()]);
            }
        });

        ActivityLog::log('updated', 'ClubInventoryIssue', null,
            "Инвентарь: принято всё от «{$client->name}» — позиций {$lines->count()}", clubId: $club->id);

        return back()->with('success', 'Инвентарь принят');
    }

    public function store(Request $request)
    {
        $club = $this->requireClub();

        $data = $request->validate(self::rules(), self::messages());

        $data['club_id'] = $club->id;
        $data['is_active'] = $request->boolean('is_active', true);

        $item = ClubInventoryItem::create($data);

        ActivityLog::log('created', 'ClubInventoryItem', $item->id,
            "Инвентарь: добавлена позиция «{$item->name}» — {$item->price} ₸", clubId: $club->id);

        return back()->with('success', 'Позиция добавлена');
    }

    public function update(Request $request, ClubInventoryItem $item)
    {
        $club = $this->requireClub();
        if ($item->club_id !== $club->id) abort(403);

        $data = $request->validate(self::rules(), self::messages());

        // Активность меняем ТОЛЬКО если поле реально пришло в запросе. Иначе частичный
        // PUT (например, из будущего мобильного API) молча выключил бы позицию.
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        } else {
            unset($data['is_active']);
        }

        $item->update($data);

        ActivityLog::log('updated', 'ClubInventoryItem', $item->id,
            "Инвентарь: изменена позиция «{$item->name}»", clubId: $club->id);

        return back()->with('success', 'Позиция обновлена');
    }

    public function destroy(ClubInventoryItem $item)
    {
        $club = $this->requireClub();
        if ($item->club_id !== $club->id) abort(403);

        $name = $item->name;
        $item->delete();

        ActivityLog::log('deleted', 'ClubInventoryItem', null,
            "Инвентарь: удалена позиция «{$name}»", clubId: $club->id);

        return back()->with('success', 'Позиция удалена');
    }
}
