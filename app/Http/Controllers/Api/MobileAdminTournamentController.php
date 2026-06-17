<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileAdminTournamentController extends Controller
{
    /**
     * GET /api/mobile/admin/clubs/{club}/tournaments
     * Все турниры клуба со всеми статусами для админа клуба.
     */
    public function index(Request $request, Club $club): JsonResponse
    {
        $user = $request->user();

        // Проверка: user должен быть админом этого клуба
        if (!$this->canManageClub($user, $club)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к этому клубу',
            ], 403);
        }

        $query = Tournament::where('club_id', $club->id);
        // Обычный модератор видит только открытые турниры (как в Web).
        // Full-access модератор и админ — все статусы.
        if (!$this->hasTournamentsFullAccess($user, $club)) {
            $query->where('status', 'open');
        }
        $tournaments = $query
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(fn($t) => $this->formatSummary($t));

        return response()->json([
            'success' => true,
            'club' => [
                'id' => $club->id,
                'name' => $club->name,
            ],
            'tournaments' => $tournaments,
            'tournaments_full_access' =>
                $this->hasTournamentsFullAccess($user, $club),
        ]);
    }

    /**
     * GET /api/mobile/personal/tournaments
     * Список личных турниров текущего игрока (созданных им).
     */
    public function personalTournaments(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->can_create_tournaments) {
            return response()->json(['success' => false, 'message' => 'Нет доступа'], 403);
        }

        $tournaments = Tournament::where('creator_id', $user->id)
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(fn($t) => $this->formatSummary($t));

        return response()->json([
            'success' => true,
            'tournaments' => $tournaments,
        ]);
    }

    /**
     * Может ли пользователь управлять клубом (читать его данные).
     * Доступ есть у:
     *  - super_admin
     *  - club_admin данного клуба
     *  - club_moderator данного клуба (любой — обычный или с full access)
     */
    private function canManageClub($user, Club $club): bool
    {
        if (!$user) return false;
        if ($user->isSuperAdmin()) return true;
        if ($user->adminClubs()->where('clubs.id', $club->id)->exists()) {
            return true;
        }
        return $user->moderatorClubs()->where('clubs.id', $club->id)->exists();
    }

    /**
     * Полные права на управление турнирами клуба
     * (создание/правка/удаление). Только админы или full-access модераторы.
     */
    private function hasTournamentsFullAccess($user, Club $club): bool
    {
        if (!$user) return false;
        return $user->hasTournamentsFullAccess($club);
    }

    /**
     * Компактная карточка турнира для списка.
     */
    private function formatSummary(Tournament $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'type' => $t->type,
            'type_name' => $t->type_name,
            'date' => $t->start_date->format('d.m.Y'),
            'time' => $t->start_date->format('H:i'),
            'datetime' => $t->start_date->toIso8601String(),
            'status' => $t->status,
            'status_name' => $t->status_name,
            'min_level' => (float) $t->min_level,
            'max_level' => (float) $t->max_level,
            'max_participants' => $t->max_participants,
            'participants_count' => $this->getParticipantsCount($t),
            'pending_count' => $this->getPendingCount($t),
        ];
    }

    /**
     * Сколько участников зарегистрировано (registered + pending).
     * Для team-турниров считаем по парам × 2.
     */
    private function getParticipantsCount(Tournament $t): int
    {
        if ($t->type === 'team') {
            return TournamentTeam::where('tournament_id', $t->id)
                ->whereIn('status', ['approved', 'pending'])
                ->count() * 2;
        }
        return $t->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count();
    }

    /**
     * Сколько заявок ждут модерации.
     */
    private function getPendingCount(Tournament $t): int
    {
        if ($t->type === 'team') {
            return TournamentTeam::where('tournament_id', $t->id)
                ->where('status', 'pending')
                ->count();
        }
        return $t->participants()
            ->wherePivot('status', 'pending')
            ->count();
    }

    /**
     * POST /api/mobile/admin/clubs/{club}/tournaments
     * Создать турнир (Этап 4). Пока поддерживается только Король корта;
     * остальные типы добавим, когда понадобятся.
     */
    public function store(Request $request, Club $club): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageClub($user, $club)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к этому клубу',
            ], 403);
        }
        if (!$this->hasTournamentsFullAccess($user, $club)) {
            return response()->json([
                'success' => false,
                'message' => 'Нет прав на создание турниров. Обратитесь к админу клуба.',
            ], 403);
        }

        $validator = Validator::make($request->all(), $this->tournamentValidationRules());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $validated['club_id'] = $club->id;
        // Рейтинговый по умолчанию; веб-админка флаг не шлёт — остаётся true.
        $validated['is_rated'] = $request->boolean('is_rated', true);
        $validated['verified_only'] = $request->boolean('verified_only', false);

        $tournament = $this->finalizeTournamentCreate($request, $validated);

        return response()->json([
            'success' => true,
            'tournament_id' => $tournament->id,
        ]);
    }

    /**
     * POST /api/mobile/personal/tournaments
     * Создание ЛИЧНОГО (приватного) турнира обычным игроком с грантом
     * can_create_tournaments. Клуба нет (creator_id = игрок), всегда
     * нерейтинговый, в публичной вкладке не показывается.
     */
    public function storePersonal(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->can_create_tournaments) {
            return response()->json([
                'success' => false,
                'message' => 'Нет доступа к созданию турниров',
            ], 403);
        }

        $validator = Validator::make($request->all(), $this->tournamentValidationRules());
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $validated['club_id'] = null;
        $validated['creator_id'] = $user->id;
        $validated['is_rated'] = false; // личные турниры всегда нерейтинговые
        $validated['verified_only'] = $request->boolean('verified_only', false);

        $tournament = $this->finalizeTournamentCreate($request, $validated);

        return response()->json([
            'success' => true,
            'tournament_id' => $tournament->id,
        ]);
    }

    private function tournamentValidationRules(): array
    {
        return [
            'type' => 'required|in:king_of_court,americano,americano_flex,bali_koc,team,round_robin',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date|after:now',
            'duration_hours' => 'nullable|integer|min:1|max:8',
            'min_level' => 'required|numeric|min:1|max:5.75',
            'max_level' => 'required|numeric|min:1|max:5.75|gte:min_level',
            'max_participants' => 'required|integer|min:2|max:128',
            'price' => 'nullable|numeric|min:0',
            'status' => 'required|in:draft,open',
            'courts' => 'nullable|array',
            'courts.*' => 'nullable|string|max:50',
            'courts_count' => 'nullable|integer|min:1|max:32',
            'reserve_count' => 'nullable|integer|min:0|max:10',
            'waitlist_size' => 'nullable|integer|min:0|max:32',
            'moderation_hours' => 'nullable|integer|min:0|max:720',
            'moderation_minutes' => 'nullable|integer|min:0|max:1440',
            'groups_count' => 'nullable|integer|in:1,2,3,4',
            'rounds_count' => 'nullable|integer|min:1|max:30',
            'teams_advance' => 'nullable|integer|in:1,2,3,4',
            'has_playoff' => 'nullable|boolean',
            'has_lower_bracket' => 'nullable|boolean',
            'has_bronze_match' => 'nullable|boolean',
            'playoff_type' => 'nullable|in:final_only,semifinal_final',
            'playoff_format' => 'nullable|in:mix,group_vs,tops,cross,balanced',
            'telegram_registration_url' => 'nullable|url|max:500',
            'is_rated' => 'nullable|boolean',
            'verified_only' => 'nullable|boolean',
            'pairing_mode' => 'nullable|in:self,admin',
        ];
    }

    /**
     * Общая часть создания турнира: нормализация полей + create + резервы.
     * $validated уже содержит club_id ИЛИ creator_id и is_rated.
     */
    private function finalizeTournamentCreate(Request $request, array $validated): Tournament
    {
        // price в БД NOT NULL — если не передали, ставим 0.
        if (!isset($validated['price']) || $validated['price'] === null) {
            $validated['price'] = 0;
        }

        // Нормализация плей-офф по чекбоксу (team теперь может быть без плей-офф).
        $validated['has_lower_bracket'] = $request->boolean('has_lower_bracket');
        $validated['has_bronze_match'] = $request->boolean('has_bronze_match');
        $validated['has_playoff'] = $request->boolean('has_playoff')
            || $validated['has_lower_bracket']
            || $validated['has_bronze_match'];
        if (!$validated['has_playoff']) {
            $validated['playoff_type'] = null;
            $validated['playoff_format'] = null;
            $validated['has_lower_bracket'] = false;
            $validated['has_bronze_match'] = false;
        }

        // Названия кортов — пустые слоты обнуляем, если массив целиком пустой — null
        if (isset($validated['courts'])) {
            $validated['courts'] = array_map(fn($c) => $c ?: null, $validated['courts']);
            if (empty(array_filter($validated['courts']))) {
                $validated['courts'] = null;
            }
        }

        $tournament = Tournament::create($validated);

        // Резервные игроки/пары
        $reserveCount = (int) ($validated['reserve_count'] ?? 0);
        if ($reserveCount > 0) {
            $reserves = \App\Models\User::where('role', 'reserve')->orderBy('id')->get();

            if ($tournament->type === 'team') {
                $needed = $reserveCount * 2;
                $reservePairs = $reserves->take($needed);
                for ($i = 0; $i + 1 < $reservePairs->count(); $i += 2) {
                    \App\Models\TournamentTeam::create([
                        'tournament_id' => $tournament->id,
                        'player1_id' => $reservePairs[$i]->id,
                        'player2_id' => $reservePairs[$i + 1]->id,
                        'status' => 'approved',
                    ]);
                }
            } else {
                foreach ($reserves->take($reserveCount) as $reserve) {
                    $tournament->participants()->attach($reserve->id, ['status' => 'registered']);
                }
            }
        }

        return $tournament;
    }
}
