<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserLevelHistory;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private function getClub()
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return null;
        }

        if ($user->isClubModerator()) {
            return $user->moderatorClubs()->first();
        }

        return $user->adminClubs()->first();
    }

    public function index(Request $request)
    {
        // Сортировка
        $sort = $request->get('sort', 'name');
        $direction = $request->get('dir', 'asc');
        if (!in_array($sort, ['name', 'created_at', 'level', 'rating'])) $sort = 'name';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'asc';

        $query = User::human()->orderBy($sort, $direction);

        // Фильтр по городу клуба
        $club = $this->getClub();
        if ($club && $club->city) {
            $clubCity = $club->city;
            if ($clubCity === 'Алматы') {
                $query->where(function($q) use ($clubCity) {
                    $q->where('city', $clubCity)
                      ->orWhereNull('city')
                      ->orWhere('city', '');
                });
            } else {
                $query->where('city', $clubCity);
            }
        }

        // Поиск
        $isSuper = auth()->user()->isSuperAdmin();
        if ($search = $request->get('search')) {
            // Имя ищем во всех написаниях: «Денис» находит и Denis.
            $variants = \App\Support\NameSearch::variants($search);
            $query->where(function($q) use ($search, $isSuper, $variants) {
                foreach ($variants as $variant) {
                    $q->orWhere('name', 'like', "%{$variant}%")
                      ->orWhere('first_name', 'like', "%{$variant}%")
                      ->orWhere('last_name', 'like', "%{$variant}%");
                }
                if (ctype_digit((string) $search)) {
                    $q->orWhere('id', (int) $search);
                }
                // Поиск по номеру телефона — только супер-админ.
                if ($isSuper) {
                    $digits = preg_replace('/\D/', '', (string) $search);
                    if ($digits !== '') {
                        $q->orWhereRaw(
                            "REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') LIKE ?",
                            ["%{$digits}%"]
                        );
                    }
                }
            });

            // Совпавшие с запросом как есть — выше найденных по другому
            // написанию имени. Приоритет ставим перед выбранной сортировкой:
            // человек искал конкретное имя, а не листал список.
            $query->reorder();
            \App\Support\NameSearch::orderExactFirst($query, $search);
            $query->orderBy($sort, $direction);
        }

        // Фильтр по уровню
        if ($exactLevel = $request->get('exact_level')) {
            $query->where('level', (float) $exactLevel);
        } elseif ($level = $request->get('level')) {
            $min = (float) $level;
            $max = $min + 0.75;
            $query->whereBetween('level', [$min, $max]);
        }

        // Фильтр по дате регистрации
        if ($dateFrom = $request->get('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Фильтр по верификации уровня
        $verified = $request->get('verified');
        if ($verified === 'unverified') {
            $query->where(function($q) {
                $q->where('level_verified', false)->orWhereNull('level_verified');
            });
        } elseif ($verified === 'verified') {
            $query->where('level_verified', true);
        }

        $users = $query->paginate(20)->withQueryString();

        // Кулдаун ручной смены рейтинга: 2 месяца с последней ручной
        // корректировки. Карта [user_id => Carbon дата_разблокировки] для
        // игроков на текущей странице. Супер-админ не ограничен — пустая карта.
        $ratingLocks = [];
        if (!auth()->user()->isSuperAdmin()) {
            $ids = $users->pluck('id')->all();
            if (!empty($ids)) {
                $rows = \App\Models\RatingHistory::whereIn('user_id', $ids)
                    ->where('reason', 'Ручная корректировка')
                    ->selectRaw('user_id, MAX(created_at) as last_manual')
                    ->groupBy('user_id')
                    ->get();
                foreach ($rows as $r) {
                    $until = \Carbon\Carbon::parse($r->last_manual)->addMonths(2);
                    if ($until->isFuture()) {
                        $ratingLocks[$r->user_id] = $until;
                    }
                }
            }
        }

        // Статистика по уровням
        $levelStatsQuery = User::human();
        if ($club && $club->city) {
            $clubCity = $club->city;
            if ($clubCity === 'Алматы') {
                $levelStatsQuery->where(function($q) use ($clubCity) {
                    $q->where('city', $clubCity)
                      ->orWhereNull('city')
                      ->orWhere('city', '');
                });
            } else {
                $levelStatsQuery->where('city', $clubCity);
            }
        }
        $levelStats = $levelStatsQuery
            ->selectRaw("
                SUM(CASE WHEN level >= 1 AND level <= 1.75 THEN 1 ELSE 0 END) as level_1,
                SUM(CASE WHEN level >= 2 AND level <= 2.75 THEN 1 ELSE 0 END) as level_2,
                SUM(CASE WHEN level >= 3 AND level <= 3.75 THEN 1 ELSE 0 END) as level_3,
                SUM(CASE WHEN level >= 4 AND level <= 4.75 THEN 1 ELSE 0 END) as level_4,
                SUM(CASE WHEN level >= 5 AND level <= 5.75 THEN 1 ELSE 0 END) as level_5
            ")
            ->first();

        // Панель выгрузки (только супер-админ): считаем, сколько попадёт в
        // файл, чтобы не выгружать вслепую. Фильтры выгрузки живут в своих
        // параметрах (levels[], played) и основной список не трогают.
        $exportFilters = $this->exportFilters($request);
        $exportCount = null;
        if ($isSuper && ($request->filled('level_from') || $request->filled('level_to')
            || $request->filled('played'))) {
            $exportCount = app(\App\Reports\UsersReportService::class)->count($exportFilters);
        }

        $clubCity = $club ? $club->city : null;
        return view('club.users.index', compact(
            'users', 'levelStats', 'clubCity', 'ratingLocks', 'isSuper',
            'exportFilters', 'exportCount'
        ));
    }

    /**
     * Выгрузка выборки игроков в Excel. Только супер-админ: в файле
     * телефоны, а клубный админ их не видит и на самой странице.
     */
    public function export(Request $request)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $filters = $this->exportFilters($request);
        $sheet = app(\App\Reports\UsersReportService::class)->sheet($filters);

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\GenericSheetExport($sheet),
            'igroki_' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    /** Разбор фильтров выгрузки — общий для страницы и для файла. */
    private function exportFilters(Request $request): array
    {
        $played = $request->get('played');

        return [
            'level_from' => $request->filled('level_from') ? (float) $request->get('level_from') : null,
            'level_to' => $request->filled('level_to') ? (float) $request->get('level_to') : null,
            'played' => in_array($played, ['yes', 'no'], true) ? $played : null,
        ];
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'level' => 'nullable|numeric|min:1|max:5.75',
            // Прямую установку рейтинга разрешаем только супер-админу (см. ниже).
            'rating' => 'nullable|integer|min:1000|max:10000',
        ]);

        $oldName = $user->name;
        $oldLevel = $user->level !== null ? (float) $user->level : null;
        $oldVerified = (bool) $user->level_verified;
        $oldRating = (int) ($user->rating ?? 0);

        // Кулдаун 2 месяца: клубный админ/модератор не может менять
        // уровень/рейтинг, если рейтинг уже правили вручную менее 2 месяцев
        // назад. Супер-админ — без ограничений. Имя менять можно всегда.
        if (!auth()->user()->isSuperAdmin()) {
            $lastManual = \App\Models\RatingHistory::where('user_id', $user->id)
                ->where('reason', 'Ручная корректировка')
                ->latest('created_at')
                ->first();
            if ($lastManual && $lastManual->created_at->copy()->addMonths(2)->isFuture()) {
                unset($validated['level'], $validated['rating']);
            }
        }

        $update = ['name' => $validated['name']];

        // Уровень можно менять любому игроку. Рейтинг пересчитывается как level * 1000 + 125.
        $newLevel = null;
        if (array_key_exists('level', $validated) && $validated['level'] !== null) {
            $newLevel = (float) $validated['level'];
            $update['level'] = $newLevel;
            $update['rating'] = (int) ($newLevel * 1000 + 125);
        }

        // Супер-админ может выставить рейтинг НАПРЯМУЮ — это ручная корректировка.
        // Приоритет над уровнем: рейтинг задаёт состояние, уровень выводим из него
        // (как после турниров: floor(rating/250)*0.25, клампим 1.0..5.75).
        if (auth()->user()->isSuperAdmin()
            && array_key_exists('rating', $validated)
            && $validated['rating'] !== null
            && (int) $validated['rating'] !== $oldRating
        ) {
            $newRating = (int) $validated['rating'];
            $update['rating'] = $newRating;
            $newLevel = max(1.0, min(5.75, floor($newRating / 250) * 0.25));
            $update['level'] = $newLevel;
        }
        // Ручная правка уровня админом верифицирует уровень ТОЛЬКО если у
        // игрока есть аватар. Без фото уровень не верифицируем, даже если
        // его выставили вручную.
        $update['level_verified'] = !empty($user->avatar);

        $user->update($update);
        $user->refresh();

        // Если рейтинг изменился — фиксируем в rating_history с tournament_id=null,
        // чтобы график «Динамика рейтинга» совпадал с актуальным users.rating.
        if (isset($update['rating']) && $update['rating'] !== $oldRating) {
            \App\Models\RatingHistory::create([
                'user_id' => $user->id,
                'tournament_id' => null,
                'rating_before' => $oldRating,
                'rating_after' => (int) $update['rating'],
                'change' => (int) $update['rating'] - $oldRating,
                'reason' => \App\Models\RatingHistory::REASON_MANUAL,
            ]);
        }

        $newVerified = (bool) $user->level_verified;

        // Логируем изменения, чтобы потом можно было понять кто/когда/что менял.
        $changes = [];
        if ($oldName !== $validated['name']) {
            $changes['name'] = ['from' => $oldName, 'to' => $validated['name']];
        }
        if ($newLevel !== null && $oldLevel !== $newLevel) {
            $changes['level'] = ['from' => $oldLevel, 'to' => $newLevel];
        }
        if (isset($update['rating']) && (int) $update['rating'] !== $oldRating) {
            $changes['rating'] = ['from' => $oldRating, 'to' => (int) $update['rating']];
        }
        if ($oldVerified !== $newVerified) {
            $changes['level_verified'] = ['from' => $oldVerified, 'to' => $newVerified];
        }

        if (!empty($changes)) {
            $action = isset($changes['level']) ? 'level_changed' : 'updated';
            $description = isset($changes['level'])
                ? sprintf(
                    'Уровень %s изменён с %s на %s',
                    $user->name,
                    $oldLevel !== null ? rtrim(rtrim(number_format($oldLevel, 2, '.', ''), '0'), '.') : '—',
                    rtrim(rtrim(number_format($newLevel, 2, '.', ''), '0'), '.')
                )
                : 'Профиль ' . $user->name . ' обновлён';

            ActivityLog::log(
                action: $action,
                subjectType: 'User',
                subjectId: $user->id,
                description: $description,
                changes: $changes,
            );
        }

        // Отдельная история уровня — публичная инфа «кто верифицировал».
        // Пишем когда менялся уровень или флаг верификации.
        if (isset($changes['level']) || isset($changes['level_verified'])) {
            $actor = auth()->user();
            $clubId = null;
            if ($actor) {
                if ($actor->isClubModerator()) {
                    $clubId = $actor->moderatorClubs()->first()?->id;
                } elseif ($actor->isClubAdmin()) {
                    $clubId = $actor->adminClubs()->first()?->id;
                }
            }

            UserLevelHistory::create([
                'user_id' => $user->id,
                'changed_by_user_id' => $actor?->id,
                'club_id' => $clubId,
                'old_level' => $oldLevel,
                'new_level' => $newLevel ?? $oldLevel,
                'old_verified' => $oldVerified,
                'new_verified' => $newVerified,
                'created_at' => now(),
            ]);
        }

        return back()->with('success', 'Пользователь обновлён!');
    }
}