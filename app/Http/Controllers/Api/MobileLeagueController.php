<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\LeaguePlayer;
use App\Models\Tournament;
use App\Support\AmericanoFlexRanking;
use App\Support\LeagueStandings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Лиги для игрока: список, карточка со сводной таблицей, запись.
 *
 * Этапы лиги — обычные турниры, поэтому открываются существующим экраном
 * турнира. Здесь только сама лига.
 */
class MobileLeagueController extends Controller
{
    /** Открытые и идущие лиги — те, куда ещё имеет смысл смотреть. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $leagues = League::whereIn('status', ['open', 'in_progress'])
            ->with('club:id,name,city,logo')
            ->orderByDesc('start_date')
            ->get();

        $mine = LeaguePlayer::where('user_id', $user->id)
            ->where('status', 'registered')
            ->pluck('league_id')
            ->flip();

        return response()->json([
            'success' => true,
            'leagues' => $leagues->map(fn ($league) => $this->card($league, isset($mine[$league->id])))->values(),
        ]);
    }

    /** Мои лиги — для профиля: где играю и на каком месте. */
    public function my(Request $request): JsonResponse
    {
        $user = $request->user();

        $rosterIds = LeaguePlayer::where('user_id', $user->id)
            ->whereIn('status', ['registered', 'left'])
            ->pluck('league_id');

        // Замена, сыгравшая один вечер, в состав лиги не записана, но в её
        // таблице стоит со своими очками — значит лига её тоже касается.
        $playedIds = Tournament::whereNotNull('league_id')
            ->whereHas('participants', fn ($q) => $q->where('users.id', $user->id))
            ->pluck('league_id');

        $leagues = League::whereIn('id', $rosterIds->merge($playedIds)->unique())
            ->with('club:id,name,city,logo')
            ->orderByDesc('start_date')
            ->get();

        return response()->json([
            'success' => true,
            'leagues' => $leagues->map(function ($league) use ($user, $rosterIds) {
                // В составе — только записанные: подмене не предлагаем
                // «отменить запись», которой у неё нет.
                $card = $this->card($league, $rosterIds->contains($league->id));
                $standings = LeagueStandings::build($league);

                foreach ($standings as $row) {
                    if ((int) $row['id'] === (int) $user->id) {
                        $card['my_place'] = $row['position'];
                        $card['my_points'] = $row['points_for'];
                        $card['my_stages'] = $row['stages'];
                        break;
                    }
                }

                $card['total_players'] = count($standings);

                return $card;
            })->values(),
        ]);
    }

    public function show(Request $request, League $league): JsonResponse
    {
        $user = $request->user();
        $league->load(['club:id,name,city,logo', 'stages' => fn ($q) => $q->orderBy('league_stage')]);

        $registered = LeaguePlayer::where('league_id', $league->id)
            ->where('user_id', $user->id)
            ->where('status', 'registered')
            ->exists();

        $standings = LeagueStandings::build($league);
        $myPlace = null;
        foreach ($standings as $row) {
            if ((int) $row['id'] === (int) $user->id) {
                $myPlace = $row['position'];
                break;
            }
        }

        return response()->json([
            'success' => true,
            'league' => array_merge($this->card($league, $registered), [
                'description' => $league->description,
                'my_place' => $myPlace,
                'stages' => $league->stages->map(fn ($stage) => [
                    'id' => $stage->id,
                    'stage' => (int) $stage->league_stage,
                    'name' => $stage->name,
                    'status' => $stage->status,
                    'status_name' => $stage->status_name,
                    'start_date' => $stage->start_date?->toIso8601String(),
                    'participants' => $stage->participants()->count(),
                    'max_participants' => $stage->max_participants,
                    // Место на этапе — вместо медальки в общей истории турниров.
                    'my_place' => $this->stagePlace($stage, (int) $user->id),
                    'my_points' => $this->stagePoints($stage, (int) $user->id),
                ])->values(),
                'standings' => collect($standings)->map(fn ($row) => [
                    'position' => $row['position'],
                    'user_id' => $row['id'],
                    'name' => $row['name'],
                    'avatar' => $row['avatar'],
                    // Галочка подтверждённого уровня — как в таблице этапа.
                    'verified' => $row['verified'],
                    'level' => $row['level'],
                    'rating' => $row['rating'],
                    'stages' => $row['stages'],
                    'wins' => $row['wins'],
                    'losses' => $row['losses'],
                    'draws' => $row['draws'],
                    'points_for' => $row['points_for'],
                    'points_against' => $row['points_against'],
                    'diff' => $row['diff'],
                    'average' => $row['average'],
                    'is_me' => (int) $row['id'] === (int) $user->id,
                ])->values(),
            ]),
        ]);
    }

    /**
     * Записаться в лигу.
     *
     * Запись одна на всю лигу: организатор дальше сам добавляет состав в
     * каждый этап. В уже созданные этапы игрок не попадает автоматически —
     * там состав мог быть собран под конкретный вечер.
     */
    public function register(Request $request, League $league): JsonResponse
    {
        $user = $request->user();

        if (!in_array($league->status, ['open', 'in_progress'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Запись в эту лигу закрыта',
            ], 422);
        }

        if ($league->max_players && $league->activePlayers()->count() >= $league->max_players) {
            return response()->json([
                'success' => false,
                'message' => 'В лиге нет свободных мест',
            ], 422);
        }

        LeaguePlayer::updateOrCreate(
            ['league_id' => $league->id, 'user_id' => $user->id],
            ['status' => 'registered', 'joined_at' => now(), 'left_at' => null]
        );

        return response()->json([
            'success' => true,
            'message' => 'Вы записаны в лигу',
        ]);
    }

    public function cancel(Request $request, League $league): JsonResponse
    {
        $user = $request->user();

        LeaguePlayer::where('league_id', $league->id)
            ->where('user_id', $user->id)
            ->update(['status' => 'left', 'left_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Запись отменена',
        ]);
    }

    /** Одинаковая карточка лиги для списка, профиля и шапки экрана. */
    /**
     * Место игрока на этапе — то самое, что раньше показывала история турниров.
     *
     * Считаем только по сыгранным: у идущего этапа место скакало бы после
     * каждого матча. Этап лиги всегда Americano Flex, поэтому порядок берём
     * из общего ранжирования формата, а не считаем заново.
     */
    private function stagePlace(Tournament $stage, int $userId): ?int
    {
        if ($stage->status !== 'completed') {
            return null;
        }

        if ($stage->isPairedFlex()) {
            $rows = app(\App\Services\AmericanoFlexService::class)->getPairedLeaderboard($stage);
            foreach ($rows as $i => $row) {
                $ids = [$row['player1']->id ?? null, $row['player2']->id ?? null];
                if (in_array($userId, $ids, true)) {
                    return $i + 1;
                }
            }

            return null;
        }

        return AmericanoFlexRanking::place($stage, $userId);
    }

    /** Очки игрока на этапе — они же идут в сводную таблицу лиги. */
    private function stagePoints(Tournament $stage, int $userId): ?int
    {
        if ($stage->status !== 'completed') {
            return null;
        }

        $stats = AmericanoFlexRanking::stats($stage);

        return isset($stats[$userId]) ? (int) $stats[$userId]['points_for'] : null;
    }

    private function card(League $league, bool $registered): array
    {
        $summary = LeagueStandings::summary($league);

        return [
            'id' => $league->id,
            'name' => $league->name,
            'status' => $league->status,
            'status_name' => $league->status_name,
            'club' => $league->club ? [
                'id' => $league->club->id,
                'name' => $league->club->name,
                'city' => $league->club->city,
                // В колонке лежит путь вида «/logos/x.png» — приложению нужна
                // готовая ссылка, иначе вместо логотипа рисуются инициалы.
                'logo' => $league->club->logo_url,
            ] : null,
            'start_date' => $league->start_date?->toIso8601String(),
            'end_date' => $league->end_date?->toIso8601String(),
            'min_level' => $league->min_level !== null ? (float) $league->min_level : null,
            'max_level' => $league->max_level !== null ? (float) $league->max_level : null,
            'price' => $league->price,
            // Формат этапов один на всю лигу — показываем его в карточке,
            // как тип у обычного турнира.
            'format' => 'americano_flex',
            'format_name' => $league->is_paired ? 'Americano Flex, парный' : 'Americano Flex',
            'is_paired' => (bool) $league->is_paired,
            'stages_total' => $summary['stages_total'],
            'stages_done' => $summary['stages_done'],
            'players' => $summary['players'],
            'max_players' => $league->max_players,
            'is_registered' => $registered,
            'next_stage' => $summary['next_stage'] ? [
                'id' => $summary['next_stage']->id,
                'stage' => (int) $summary['next_stage']->league_stage,
                'name' => $summary['next_stage']->name,
                'start_date' => $summary['next_stage']->start_date?->toIso8601String(),
            ] : null,
        ];
    }
}
