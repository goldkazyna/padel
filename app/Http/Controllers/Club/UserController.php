<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
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
        if (!in_array($sort, ['name', 'created_at', 'level'])) $sort = 'name';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'asc';

        $query = User::where('role', 'player')->orderBy($sort, $direction);

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
        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
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

        // Статистика по уровням
        $levelStatsQuery = User::where('role', 'player');
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

        $clubCity = $club ? $club->city : null;
        return view('club.users.index', compact('users', 'levelStats', 'clubCity'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'level' => 'nullable|numeric|min:1|max:5.75',
        ]);

        $oldName = $user->name;
        $oldLevel = $user->level !== null ? (float) $user->level : null;
        $oldVerified = (bool) $user->level_verified;

        $update = ['name' => $validated['name']];

        // Уровень можно менять любому игроку. Рейтинг пересчитывается как level * 1000 + 125.
        // При сохранении формы уровень всегда считается подтверждённым админом
        // клуба — выставляем level_verified = true автоматически.
        $newLevel = null;
        if (array_key_exists('level', $validated) && $validated['level'] !== null) {
            $newLevel = (float) $validated['level'];
            $update['level'] = $newLevel;
            $update['rating'] = (int) ($newLevel * 1000 + 125);
        }
        $update['level_verified'] = true;

        $user->update($update);

        // Логируем изменения, чтобы потом можно было понять кто/когда/что менял.
        $changes = [];
        if ($oldName !== $validated['name']) {
            $changes['name'] = ['from' => $oldName, 'to' => $validated['name']];
        }
        if ($newLevel !== null && $oldLevel !== $newLevel) {
            $changes['level'] = ['from' => $oldLevel, 'to' => $newLevel];
        }
        if ($oldVerified !== true) {
            $changes['level_verified'] = ['from' => $oldVerified, 'to' => true];
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

        return back()->with('success', 'Пользователь обновлён!');
    }
}