<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LevelQuizService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MobileProfileController extends Controller
{
    /**
     * Вопросы опросника (для показа на фронте).
     * GET /api/mobile/profile/quiz
     */
    public function quizQuestions()
    {
        return response()->json([
            'success' => true,
            'questions' => LevelQuizService::questions(),
        ]);
    }

    /**
     * Приём ответов опросника — вычисляет уровень, сохраняет.
     * POST /api/mobile/profile/quiz
     */
    public function submitQuiz(Request $request, LevelQuizService $service)
    {
        $user = $request->user();

        if ($user->quiz_completed) {
            return response()->json([
                'success' => false,
                'message' => 'Опросник уже пройден',
            ], 422);
        }

        $validated = $request->validate([
            'answers' => 'required|array|size:5',
            'answers.*' => 'required|integer|min:0|max:5',
        ]);

        $result = $service->evaluate($validated['answers']);
        if (empty($result['valid'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Ошибка',
            ], 422);
        }

        $user->update([
            'quiz_completed' => true,
            'quiz_answers' => $validated['answers'],
            'level' => $result['level'],
            // Рейтинг = level × 1000 + 125 (консистентно с Club\UserController)
            'rating' => (int) ($result['level'] * 1000 + 125),
            'level_verified' => false,
        ]);

        return response()->json([
            'success' => true,
            'level' => $result['level'],
            'score' => $result['score'],
            'max_score' => $result['max_score'],
            'category' => $result['category'],
        ]);
    }

    /**
     * Профиль текущего пользователя
     * GET /api/mobile/profile
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $matchStats = $user->getAllMatchesStats();
        $tournamentStats = $user->getTournamentStats();

        $place = null;
        if ($user->rating) {
            $place = User::visibleInRating()
                ->where('rating', '>', $user->rating)
                ->count() + 1;
        }

        // Тренд рейтинга для графика «Динамика рейтинга».
        // Каждый турнир = одна точка (берём финальный rating_after турнира).
        // Каждая ручная правка (tournament_id = null) = отдельная точка.
        $rawHistory = \App\Models\RatingHistory::where('user_id', $user->id)
            ->whereNotNull('rating_after')
            ->orderBy('id', 'asc')
            ->with('club:id,name')
            ->get(['id', 'tournament_id', 'club_id', 'rating_after', 'created_at']);

        $entries = [];
        $prevTournamentId = -1; // -1 = «нет предыдущей»
        foreach ($rawHistory as $h) {
            $tid = $h->tournament_id !== null ? (int) $h->tournament_id : null;
            if ($tid !== null && $tid === $prevTournamentId) {
                // Тот же турнир — обновляем последнюю запись (финальный rating_after)
                $last = count($entries) - 1;
                $entries[$last]['rating_after'] = (int) $h->rating_after;
                $entries[$last]['created_at'] = $h->created_at;
            } else {
                $entries[] = [
                    'tournament_id' => $tid,
                    'rating_after' => (int) $h->rating_after,
                    'created_at' => $h->created_at,
                    'club_name' => $h->club?->name,
                ];
                $prevTournamentId = $tid ?? -1;
            }
        }
        $entries = array_slice($entries, -10);

        $ratingTrend = array_map(fn($e) => $e['rating_after'], $entries);

        // Подгружаем турниры для отображения названий/клубов/дат
        $tournamentIds = array_values(array_filter(array_map(
            fn($e) => $e['tournament_id'],
            $entries,
        ), fn($v) => $v !== null));
        $tournaments = $tournamentIds
            ? \App\Models\Tournament::whereIn('id', $tournamentIds)
                ->with('club')
                ->get()
                ->keyBy('id')
            : collect();

        $details = [];
        $prev = null;
        foreach ($entries as $entry) {
            $rating = (int) $entry['rating_after'];
            $delta = $prev === null ? null : $rating - $prev;
            if ($entry['tournament_id'] === null) {
                // Ручная правка админом — точка без турнира.
                $details[] = [
                    'tournament_id' => null,
                    'name' => 'Ручная корректировка',
                    // Клуб администратора, который правил рейтинг. У правок,
                    // сделанных до появления этого поля, его нет — там
                    // остаётся прежняя подпись.
                    'club_name' => $entry['club_name'] ?? 'Padel Kz',
                    'date' => $entry['created_at']?->translatedFormat('j M Y'),
                    'rating' => $rating,
                    'delta' => $delta,
                    'is_manual' => true,
                ];
            } else {
                $t = $tournaments[$entry['tournament_id']] ?? null;
                $details[] = [
                    'tournament_id' => $entry['tournament_id'],
                    'name' => $t?->name ?? 'Турнир',
                    'club_name' => $t?->club?->name,
                    'date' => $t?->start_date?->translatedFormat('j M Y'),
                    'rating' => $rating,
                    'delta' => $delta,
                    'is_manual' => false,
                ];
            }
            $prev = $rating;
        }

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user, $place),
            'statistics' => [
                'matches_played' => $matchStats['total'],
                'wins' => $matchStats['won'],
                'losses' => $matchStats['lost'],
                // Ничьи в знаменатель не идут: вечер с ничьими не должен
                // выглядеть как вечер с поражениями.
                'winrate' => \App\Support\CountedMatches::winrate(
                    $matchStats['won'],
                    $matchStats['lost']
                ),
                'draws' => $matchStats['draw'] ?? 0,
                'tournaments_count' => $tournamentStats['total'],
                'rating_trend' => $ratingTrend,
                'rating_trend_details' => $details,
            ],
        ]);
    }

    /**
     * Обновление профиля
     * PUT /api/mobile/profile
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'nullable|string|max:255',
            'patronymic'  => 'nullable|string|max:100',
            'city'        => 'nullable|string|in:' . implode(',', config('cities')),
            'gender'      => 'nullable|string|in:male,female',
            'age'         => 'nullable|integer|min:1|max:99',
            'birth_date'  => 'nullable|date|before:today',
            'hand'        => 'nullable|string|in:right,left',
            'position'    => 'nullable|string|in:right,left,any',
            'phone'       => 'nullable|string|max:20',
        ]);

        $user = $request->user();

        // Телефон разрешаем записать только если он ещё не задан у пользователя
        if (array_key_exists('phone', $validated)) {
            $rawPhone = $validated['phone'];
            unset($validated['phone']);

            if (empty($user->phone) && !empty($rawPhone)) {
                $digits = preg_replace('/[^0-9]/', '', $rawPhone);
                if (strlen($digits) === 11 && $digits[0] === '8') {
                    $digits = '7' . substr($digits, 1);
                } elseif (strlen($digits) === 10) {
                    $digits = '7' . $digits;
                }

                if (strlen($digits) !== 11 || $digits[0] !== '7') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Неверный формат телефона',
                    ], 422);
                }

                if (User::where('phone', $digits)->where('id', '!=', $user->id)->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Этот телефон уже используется другим аккаунтом',
                    ], 422);
                }

                $user->phone = $digits;
            }
        }

        $user->fill($validated);

        $user->save();

        $place = null;
        if ($user->rating) {
            $place = User::visibleInRating()
                ->where('rating', '>', $user->rating)
                ->count() + 1;
        }

        return response()->json([
            'success' => true,
            'message' => 'Профиль обновлён',
            'user' => $this->formatUser($user, $place),
        ]);
    }

    /**
     * Загрузка аватара
     * POST /api/mobile/profile/avatar
     */
    public function avatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();

        // Удалить старый аватар
        if ($user->avatar) {
            $oldPath = str_replace('/storage/', '', parse_url($user->avatar, PHP_URL_PATH));
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar = url('/storage/' . $path);
        $user->save();

        // Триггер верификации: появился аватар → пересчитать level_verified.
        // Только если клуб последнего завершённого турнира имеет право на
        // пользователей/уровни — иначе авто-верификации нет (как и после турнира).
        $oldVerified = (bool) $user->level_verified;
        $ref = $user->lastCompletedTournamentRef();
        $refClub = ($ref['club_id'] ?? null)
            ? \App\Models\Club::find($ref['club_id'])
            : null;
        if ($refClub && $refClub->canVerifyLevels() && $user->recomputeLevelVerified()) {
            $newVerified = (bool) $user->level_verified;
            if ($newVerified && !$oldVerified) {
                \App\Models\UserLevelHistory::create([
                    'user_id' => $user->id,
                    'changed_by_user_id' => null, // системное событие
                    'club_id' => $ref['club_id'] ?? null,
                    'old_level' => $user->level !== null ? (float) $user->level : null,
                    'new_level' => $user->level !== null ? (float) $user->level : null,
                    'old_verified' => false,
                    'new_verified' => true,
                    'created_at' => now(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'avatar_url' => $user->avatar,
        ]);
    }

    private function formatUser(User $user, ?int $place): array
    {
        $isClubAdmin = $user->isClubAdmin();
        $isClubModerator = $user->isClubModerator();
        $adminClubs = $isClubAdmin
            ? $user->adminClubs()
                ->select('clubs.id', 'clubs.name', 'clubs.features')
                ->get()
                ->map(fn($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'features' => $c->features ?? [],
                ])
                ->toArray()
            : [];
        $moderatorClubs = $isClubModerator
            ? $user->moderatorClubs()
                ->select('clubs.id', 'clubs.name', 'clubs.features')
                ->get()
                ->map(fn($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'features' => $c->features ?? [],
                    'tournaments_full_access' =>
                        (bool) ($c->pivot->tournaments_full_access ?? false),
                ])
                ->toArray()
            : [];

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'rating' => $user->rating,
            'level' => $user->level,
            'level_name' => $user->level_name,
            'place' => $place,
            'level_verified' => (bool) $user->level_verified,
            'verification_blockers' => $user->verificationBlockers(),
            'quiz_completed' => (bool) $user->quiz_completed,
            'patronymic' => $user->patronymic,
            'city' => $user->city,
            'gender' => $user->gender,
            'age' => $user->age,
            'birth_date' => $user->birth_date ? $user->birth_date->format('Y-m-d') : null,
            'hand' => $user->hand,
            'position' => $user->position,
            'role' => $user->role,
            'is_club_admin' => $isClubAdmin,
            'admin_clubs' => $adminClubs,
            'is_club_moderator' => $isClubModerator,
            'moderator_clubs' => $moderatorClubs,
            'can_create_tournaments' => (bool) $user->can_create_tournaments,
        ];
    }
}
