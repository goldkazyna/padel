<?php

namespace App\Support;

use App\Models\RatingHistory;
use App\Models\Tournament;
use App\Models\User;

/**
 * Точки графика «Динамика рейтинга».
 *
 * Одна точка — один турнир (берём итоговый rating_after, а не каждую запись
 * внутри турнира) либо одна правка со стороны: ручная корректировка админом
 * или списание за простой.
 *
 * Живёт отдельно от контроллера: тот же список нужен и в профиле (последние
 * десять), и на экране всей истории. Считать его двумя разными кусками кода
 * значит однажды получить два разных графика.
 */
class RatingTrend
{
    /** Сколько точек показываем в карточке профиля. */
    public const CARD_POINTS = 10;

    /**
     * @return array<int, array<string, mixed>> от старых к новым
     */
    public static function points(User $user, ?int $limit = null): array
    {
        $raw = RatingHistory::where('user_id', $user->id)
            ->whereNotNull('rating_after')
            ->orderBy('id', 'asc')
            ->with('club:id,name')
            ->get(['id', 'tournament_id', 'club_id', 'rating_after', 'reason', 'created_at']);

        $entries = [];
        $prevTournamentId = -1; // -1 = «нет предыдущей»
        foreach ($raw as $h) {
            $tid = $h->tournament_id !== null ? (int) $h->tournament_id : null;

            if ($tid !== null && $tid === $prevTournamentId) {
                // Тот же турнир — обновляем последнюю точку итоговым рейтингом.
                $last = count($entries) - 1;
                $entries[$last]['rating_after'] = (int) $h->rating_after;
                $entries[$last]['created_at'] = $h->created_at;
                continue;
            }

            $entries[] = [
                'tournament_id' => $tid,
                'rating_after' => (int) $h->rating_after,
                'created_at' => $h->created_at,
                'club_name' => $h->club?->name,
                'reason' => $h->reason,
            ];
            $prevTournamentId = $tid ?? -1;
        }

        // Дельту считаем до обрезки: у первой показанной точки она честная,
        // а не «ноль, потому что раньше ничего не показываем».
        $prev = null;
        foreach ($entries as $i => $entry) {
            $rating = (int) $entry['rating_after'];
            $entries[$i]['delta'] = $prev === null ? null : $rating - $prev;
            $prev = $rating;
        }

        if ($limit !== null) {
            $entries = array_slice($entries, -$limit);
        }

        return self::decorate($entries);
    }

    /**
     * Дописать названия турниров одним запросом.
     *
     * @param  array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private static function decorate(array $entries): array
    {
        $ids = array_values(array_filter(
            array_column($entries, 'tournament_id'),
            fn ($v) => $v !== null
        ));

        $tournaments = $ids
            ? Tournament::whereIn('id', $ids)->with('club')->get()->keyBy('id')
            : collect();

        $out = [];
        foreach ($entries as $entry) {
            $rating = (int) $entry['rating_after'];

            if ($entry['tournament_id'] === null) {
                // Правка со стороны: ручная корректировка или списание за
                // простой. Подпись берём из причины — иначе обе выглядят
                // одинаково и человек не понимает, откуда минус.
                $out[] = [
                    'tournament_id' => null,
                    'name' => $entry['reason'] ?: 'Ручная корректировка',
                    // Клуб администратора, который правил рейтинг. У правок,
                    // сделанных до появления этого поля, его нет.
                    'club_name' => $entry['club_name'] ?? 'Padel Kz',
                    'date' => $entry['created_at']?->translatedFormat('j M Y'),
                    'rating' => $rating,
                    'delta' => $entry['delta'],
                    'is_manual' => true,
                ];
                continue;
            }

            $t = $tournaments[$entry['tournament_id']] ?? null;
            $out[] = [
                'tournament_id' => $entry['tournament_id'],
                'name' => $t?->name ?? 'Турнир',
                'club_name' => $t?->club?->name,
                'date' => $t?->start_date?->translatedFormat('j M Y'),
                'rating' => $rating,
                'delta' => $entry['delta'],
                'is_manual' => false,
            ];
        }

        return $out;
    }
}
