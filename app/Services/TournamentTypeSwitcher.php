<?php

namespace App\Services;

use App\Models\Tournament;

/**
 * Смена типа турнира до старта.
 *
 * Состав у одиночных форматов лежит в одной таблице `tournament_participants`
 * и от типа не зависит — записавшиеся переживают смену как есть. Меняются
 * только настройки формата: поля чужого типа нужно вычистить, поля нового —
 * выставить по умолчанию, а лимит участников подогнать под его требования.
 *
 * После старта тип не меняется: сетка уже разложена по своим таблицам, и
 * переносить сыгранное между форматами бессмысленно.
 */
class TournamentTypeSwitcher
{
    /** Форматы, между которыми переключаемся: записываются поодиночке. */
    public const SOLO_TYPES = [
        'americano',
        'mexicano',
        'king_of_court',
        'round_robin',
        'just_padel_it',
        'americano_flex',
        'escalera',
    ];

    /** Человеческие названия — для подсказок и ошибок. */
    public const TYPE_NAMES = [
        'americano' => 'Американо',
        'mexicano' => 'Мексикано',
        'king_of_court' => 'Король корта',
        'round_robin' => 'Round Robin',
        'just_padel_it' => 'Just Padel It',
        'americano_flex' => 'Americano Flex',
        'escalera' => 'Ladder',
    ];

    /**
     * Можно ли вообще предлагать смену типа.
     *
     * Парные турниры не трогаем: там состав хранится парами (`tournament_teams`,
     * `*_pairs`), и переход в одиночный формат стёр бы записи.
     */
    public function canSwitch(Tournament $tournament): bool
    {
        return in_array($tournament->status, ['draft', 'open'], true)
            && in_array($tournament->type, self::SOLO_TYPES, true)
            && !$tournament->is_paired;
    }

    /** Типы, доступные для выбора (включая текущий). */
    public function availableTypes(Tournament $tournament): array
    {
        return $this->canSwitch($tournament) ? self::SOLO_TYPES : [$tournament->type];
    }

    /**
     * Собрать изменения полей под новый тип.
     *
     * Возвращает массив для `update()`. Участников не трогает — их число
     * может не подходить новому формату, и это нормально: организатор
     * дособирает состав, а старт откроется сам, когда людей станет столько,
     * сколько нужно.
     *
     * @return array<string, mixed>
     */
    public function changesFor(Tournament $tournament, string $newType): array
    {
        $registered = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count();

        $changes = ['type' => $newType];

        // Поля чужих форматов гасим все разом, дальше поднимаем нужные.
        // Часть колонок NOT NULL — им возвращаем значения по умолчанию,
        // а не null, иначе update падает на ограничении базы.
        $changes += [
            'groups_count' => 1,
            'rounds_count' => null,
            'teams_advance' => 2,
            'points_to_win' => 16,
            'has_playoff' => false,
            'playoff_type' => null,
            'playoff_format' => null,
            'has_lower_bracket' => false,
            'has_bronze_match' => false,
            'courts_count' => null,
            'jpi_rank_by_wins' => false,
            'round_robin_schedule' => null,
            'escalera_standings_mode' => 'raw_points',
            'is_paired' => false,
            'pairing_mode' => 'self',
        ];

        $limit = (int) $tournament->max_participants;

        switch ($newType) {
            case 'americano':
                // Игроки делятся на группы, в каждой корты по четверо.
                // Одна группа по умолчанию — организатор поменяет, если нужно.
                $changes['groups_count'] = 1;
                $changes['max_participants'] = $this->roundUpTo($limit, 4, 4, $registered);
                break;

            case 'mexicano':
                $changes['max_participants'] = $this->roundUpTo($limit, 4, 8, $registered);
                $changes['rounds_count'] = 7;
                break;

            case 'king_of_court':
            case 'just_padel_it':
                $changes['max_participants'] = $this->roundUpTo($limit, 4, 8, $registered);
                break;

            case 'round_robin':
                $changes['max_participants'] = $this->roundUpTo($limit, 4, 4, $registered);
                break;

            case 'americano_flex':
                // Кратность не нужна: лишние ждут своей очереди на отдыхе.
                $changes['max_participants'] = max($limit, $registered, 5);
                $changes['courts_count'] = max(1, min(8, intdiv((int) $changes['max_participants'], 4)));
                break;

            case 'escalera':
                // Жёсткая связка: играют ровно кортов × 4.
                $need = max($limit, $registered, 8);
                $courtsNeeded = max(2, min(10, (int) ceil($need / 4)));
                $changes['courts_count'] = $courtsNeeded;
                $changes['max_participants'] = $courtsNeeded * 4;
                break;
        }

        // Число раундов Американо считаем от состава группы, а не от лимита.
        if ($newType === 'americano') {
            $perGroup = intdiv((int) $changes['max_participants'], (int) $changes['groups_count']);
            $changes['rounds_count'] = max(1, $perGroup - 1);
        }

        return $changes;
    }

    /**
     * Что показать организатору после смены: сколько людей нужно для старта.
     * Возвращает null, если состав уже подходит.
     */
    public function startHint(Tournament $tournament): ?string
    {
        $registered = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count();
        $limit = (int) $tournament->max_participants;

        if ($registered === $limit) {
            return null;
        }

        $name = self::TYPE_NAMES[$tournament->type] ?? $tournament->type;
        $left = $limit - $registered;

        if ($left > 0) {
            return "{$name}: записано {$registered} из {$limit}. "
                . "Старт откроется, когда наберётся ещё " . $this->plural($left, 'игрок', 'игрока', 'игроков') . '.';
        }

        return "{$name}: записано {$registered}, а лимит {$limit}. "
            . 'Поднимите лимит или снимите лишних — иначе турнир не стартует.';
    }

    /**
     * Ближайшее подходящее число участников: кратное $step, не меньше $min
     * и не меньше уже записавшихся.
     */
    private function roundUpTo(int $current, int $step, int $min, int $registered): int
    {
        $base = max($current, $registered, $min);

        return (int) (ceil($base / $step) * $step);
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $n10 = $n % 10;
        $n100 = $n % 100;

        if ($n10 === 1 && $n100 !== 11) {
            return "{$n} {$one}";
        }
        if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) {
            return "{$n} {$few}";
        }

        return "{$n} {$many}";
    }
}
