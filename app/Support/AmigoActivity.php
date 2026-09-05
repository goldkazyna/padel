<?php

namespace App\Support;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\RatingHistory;
use App\Models\Tournament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Чем заняты амигос: играет сейчас, собирается играть, как сыграл.
 *
 * Считаем сразу на всю пачку игроков, а не по одному: экран открывают часто,
 * а список у человека — это десятки людей. Один запрос на источник, дальше
 * сборка в памяти.
 *
 * Что не показываем никогда:
 * - приватные игры (`visibility = private`) — это не публичная активность;
 * - игроков, скрытых из рейтинга (`hidden_from_rating`) — человек попросил
 *   его не показывать, значит и здесь не показываем;
 * - брони кортов — личное расписание, а не игра.
 */
class AmigoActivity
{
    /** Насколько вперёд считаем турнир «скоро». */
    private const SOON_HOURS = 36;

    /** Сколько секунд держим посчитанное: статус меняется редко, экран открывают часто. */
    public const CACHE_SECONDS = 45;

    /**
     * Статус каждого игрока из списка.
     *
     * @param  array<int, int> $userIds
     * @return array<int, array{kind: string, title: string, subtitle: string, tournament_id?: int, game_id?: int}>
     */
    public static function statuses(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return [];
        }

        $statuses = [];

        // Самое важное идёт последним: playing перетирает soon, soon — looking.
        foreach ([self::looking($userIds), self::soon($userIds), self::playing($userIds)] as $source) {
            foreach ($source as $userId => $status) {
                $statuses[$userId] = $status;
            }
        }

        return $statuses;
    }

    /** То же с коротким кешем — для экрана списка и карточки в профиле. */
    public static function cached(array $userIds): array
    {
        sort($userIds);
        $key = 'amigo_activity:' . md5(implode(',', $userIds));

        return Cache::remember($key, self::CACHE_SECONDS, fn () => self::statuses($userIds));
    }

    /** Кто прямо сейчас на корте: идущий турнир или идущая игра. */
    private static function playing(array $userIds): array
    {
        $out = [];

        $tournaments = DB::table('tournament_participants')
            ->join('tournaments', 'tournament_participants.tournament_id', '=', 'tournaments.id')
            ->leftJoin('clubs', 'tournaments.club_id', '=', 'clubs.id')
            ->whereIn('tournament_participants.user_id', $userIds)
            ->where('tournament_participants.status', 'registered')
            ->where('tournaments.status', 'in_progress')
            ->get([
                'tournament_participants.user_id',
                'tournaments.id as tournament_id',
                'tournaments.name',
                'tournaments.type',
                'clubs.name as club_name',
            ]);

        foreach ($tournaments as $row) {
            $out[(int) $row->user_id] = [
                'kind' => 'playing',
                'title' => 'играет',
                'subtitle' => trim(($row->name ?: 'Турнир') . ($row->club_name ? ' · ' . $row->club_name : '')),
                'tournament_id' => (int) $row->tournament_id,
            ];
        }

        $games = DB::table('game_players')
            ->join('games', 'game_players.game_id', '=', 'games.id')
            ->leftJoin('clubs', 'games.club_id', '=', 'clubs.id')
            ->whereIn('game_players.user_id', $userIds)
            ->where('game_players.status', GamePlayer::STATUS_ACCEPTED)
            ->where('games.status', Game::STATUS_IN_PROGRESS)
            ->where('games.visibility', Game::VISIBILITY_PUBLIC)
            ->get(['game_players.user_id', 'games.id as game_id', 'clubs.name as club_name']);

        foreach ($games as $row) {
            $out[(int) $row->user_id] = [
                'kind' => 'playing',
                'title' => 'играет',
                'subtitle' => trim('Игра' . ($row->club_name ? ' · ' . $row->club_name : '')),
                'game_id' => (int) $row->game_id,
            ];
        }

        return $out;
    }

    /** У кого турнир на ближайшие полтора суток. */
    private static function soon(array $userIds): array
    {
        $rows = DB::table('tournament_participants')
            ->join('tournaments', 'tournament_participants.tournament_id', '=', 'tournaments.id')
            ->leftJoin('clubs', 'tournaments.club_id', '=', 'clubs.id')
            ->whereIn('tournament_participants.user_id', $userIds)
            ->where('tournament_participants.status', 'registered')
            ->whereIn('tournaments.status', ['open', 'closed', 'full'])
            ->whereBetween('tournaments.start_date', [now(), now()->addHours(self::SOON_HOURS)])
            ->orderBy('tournaments.start_date')
            ->get([
                'tournament_participants.user_id',
                'tournaments.id as tournament_id',
                'tournaments.name',
                'tournaments.start_date',
                'clubs.name as club_name',
            ]);

        $out = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            // Первый по времени и есть ближайший — дальше не перетираем.
            if (isset($out[$userId])) {
                continue;
            }

            $start = Carbon::parse($row->start_date);
            $out[$userId] = [
                'kind' => 'soon',
                'title' => 'турнир ' . self::whenWord($start),
                // Время отдельным полем: приложение покажет его на своём языке,
                // а готовую русскую строку оставляем как запасной вариант.
                'at' => $start->toIso8601String(),
                'subtitle' => trim(($row->name ?: 'Турнир') . ($row->club_name ? ' · ' . $row->club_name : '')),
                'tournament_id' => (int) $row->tournament_id,
            ];
        }

        return $out;
    }

    /** Кто ищет людей в свою игру: игра открыта и мест не хватает. */
    private static function looking(array $userIds): array
    {
        $rows = DB::table('game_players')
            ->join('games', 'game_players.game_id', '=', 'games.id')
            ->leftJoin('clubs', 'games.club_id', '=', 'clubs.id')
            ->whereIn('game_players.user_id', $userIds)
            ->where('game_players.status', GamePlayer::STATUS_ACCEPTED)
            ->where('games.status', Game::STATUS_OPEN)
            ->where('games.visibility', Game::VISIBILITY_PUBLIC)
            ->where('games.starts_at', '>', now())
            ->orderBy('games.starts_at')
            ->get([
                'game_players.user_id',
                'games.id as game_id',
                'games.starts_at',
                'clubs.name as club_name',
            ]);

        $out = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            if (isset($out[$userId])) {
                continue;
            }

            $start = Carbon::parse($row->starts_at);
            $out[$userId] = [
                'kind' => 'looking',
                'title' => 'ищет игроков',
                'at' => $start->toIso8601String(),
                'subtitle' => trim(self::whenWord($start) . ($row->club_name ? ' · ' . $row->club_name : '')),
                'game_id' => (int) $row->game_id,
            ];
        }

        return $out;
    }

    /**
     * Лента: те же события плюс сыгранные турниры за последнюю неделю.
     *
     * @param  array<int, int> $userIds
     * @return array<int, array<string, mixed>> отсортировано от свежего к старому
     */
    public static function feed(array $userIds, int $limit = 40): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return [];
        }

        $events = [];

        foreach (self::statuses($userIds) as $userId => $status) {
            $events[] = [
                'user_id' => $userId,
                'kind' => $status['kind'],
                'title' => $status['title'],
                'subtitle' => $status['subtitle'],
                'tournament_id' => $status['tournament_id'] ?? null,
                'game_id' => $status['game_id'] ?? null,
                'starts_at' => $status['at'] ?? null,
                'at' => now()->toIso8601String(),
            ];
        }

        $played = RatingHistory::whereIn('user_id', $userIds)
            ->whereNotNull('tournament_id')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['user_id', 'tournament_id', 'change', 'created_at']);

        $tournaments = Tournament::whereIn('id', $played->pluck('tournament_id')->unique())
            ->get(['id', 'name', 'type'])
            ->keyBy('id');

        foreach ($played as $row) {
            $tournament = $tournaments[$row->tournament_id] ?? null;
            $change = (int) $row->change;

            $events[] = [
                'user_id' => (int) $row->user_id,
                'kind' => 'played',
                'title' => 'сыграл турнир',
                'subtitle' => trim(($tournament->name ?? 'Турнир')
                    . ($change !== 0 ? ' · ' . ($change > 0 ? '+' : '') . $change . ' рейтинга' : '')),
                'tournament_id' => (int) $row->tournament_id,
                'game_id' => null,
                'rating_change' => $change,
                'at' => Carbon::parse($row->created_at)->toIso8601String(),
            ];
        }

        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $limit);
    }

    /** «сегодня 19:00» / «завтра 20:30» / «7 сент. 19:00». */
    private static function whenWord(Carbon $moment): string
    {
        $time = $moment->format('H:i');

        if ($moment->isToday()) {
            return 'сегодня ' . $time;
        }
        if ($moment->isTomorrow()) {
            return 'завтра ' . $time;
        }

        return $moment->translatedFormat('j MMM') . ' ' . $time;
    }
}
