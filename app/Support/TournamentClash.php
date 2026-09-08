<?php

namespace App\Support;

use App\Models\Tournament;
use App\Models\User;

/**
 * Пересечение по времени: играть в двух турнирах разом нельзя.
 *
 * Человек записывался на два турнира на один вечер и узнавал об этом уже на
 * корте — организатор ждал игрока, которого в это время ждали в другом клубе.
 * Проверяем при записи и заранее гасим кнопку в карточке.
 */
class TournamentClash
{
    /**
     * Сколько длится турнир, если организатор не указал.
     *
     * Два часа — самая частая длительность на проде (335 турниров против
     * 22 трёхчасовых), и у половины поле пустое.
     */
    public const DEFAULT_HOURS = 2;

    /**
     * Турнир, который у игрока уже занят на это же время, или null.
     */
    public static function find(Tournament $tournament, User $user): ?Tournament
    {
        if (!$tournament->start_date) {
            return null;
        }

        $start = $tournament->start_date->copy();
        $end = $start->copy()->addHours(self::hours($tournament));

        $candidates = Tournament::query()
            ->whereKeyNot($tournament->id)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('start_date')
            // Окно на сутки шире: пересечение считаем уже по длительности,
            // а в выборку берём только соседние по времени.
            ->whereBetween('start_date', [$start->copy()->subDay(), $end->copy()->addDay()])
            ->whereHas('participants', function ($q) use ($user) {
                $q->where('users.id', $user->id)
                    ->whereIn('tournament_participants.status', ['registered', 'pending']);
            })
            ->with('club:id,name')
            ->get();

        foreach ($candidates as $other) {
            $otherStart = $other->start_date->copy();
            $otherEnd = $otherStart->copy()->addHours(self::hours($other));

            // Касание границами — не пересечение: турнир в 19:00 после
            // турнира 17:00–19:00 сыграть можно.
            if ($start < $otherEnd && $otherStart < $end) {
                return $other;
            }
        }

        return null;
    }

    /** Текст для игрока: где именно он уже занят. */
    public static function message(Tournament $other): string
    {
        $time = $other->start_date?->format('H:i');
        $date = $other->start_date?->format('d.m');
        $club = $other->club->name ?? '';

        return trim("В это время вы уже играете: «{$other->name}»"
            . ($date ? ", {$date} в {$time}" : '')
            . ($club ? " ({$club})" : '')
            . '. Отмените ту запись или выберите другой турнир.');
    }

    /** Длительность турнира в часах. */
    private static function hours(Tournament $tournament): int
    {
        $hours = (int) ($tournament->duration_hours ?? 0);

        return $hours > 0 ? $hours : self::DEFAULT_HOURS;
    }
}
