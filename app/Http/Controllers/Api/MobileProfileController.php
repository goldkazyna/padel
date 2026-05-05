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
            $place = User::where('role', 'player')
                ->where('rating', '>', $user->rating)
                ->count() + 1;
        }

        // Тренд рейтинга — одно значение на турнир (финальный rating_after),
        // чтобы совпадал со списком "История турниров"
        $ratingTrend = \App\Models\RatingHistory::where('user_id', $user->id)
            ->whereNotNull('tournament_id')
            ->whereNotNull('rating_after')
            ->orderBy('id', 'asc')
            ->get(['tournament_id', 'rating_after'])
            ->groupBy('tournament_id')
            ->map(fn($group) => $group->last()->rating_after)
            ->values()
            ->toArray();
        $ratingTrend = array_slice($ratingTrend, -10);
        $ratingTrend = array_map('intval', $ratingTrend);

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user, $place),
            'statistics' => [
                'matches_played' => $matchStats['total'],
                'wins' => $matchStats['won'],
                'losses' => $matchStats['lost'],
                'winrate' => $matchStats['total'] > 0
                    ? (int) round(($matchStats['won'] / $matchStats['total']) * 100)
                    : 0,
                'tournaments_count' => $tournamentStats['total'],
                'rating_trend' => array_map('intval', $ratingTrend),
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
            'city'        => 'nullable|string|in:Алматы,Астана,Шымкент,Караганда,Актобе',
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
            $place = User::where('role', 'player')
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

        return response()->json([
            'success' => true,
            'avatar_url' => $user->avatar,
        ]);
    }

    private function formatUser(User $user, ?int $place): array
    {
        $isClubAdmin = $user->isClubAdmin();
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
        ];
    }
}
