<?php

namespace App\Achievements\Rules;

use App\Achievements\Achievement;
use App\Achievements\PlayerHistory;

/**
 * Форматы считаются по типу турнира, а не по полю format из матча:
 * там лежат стадии, и классический турнир превратился бы в два формата.
 *
 * Bali KOC и «Классический» в зачёт не идут: клубы их не проводят (на проде
 * нет ни одного завершённого), и значок с ними был недостижим — его не
 * получил ни один игрок.
 */
class FormatsAll implements Achievement
{
    /** Форматы, которые реально проводятся клубами. */
    public const COUNTED = [
        'americano',
        'mexicano',
        'americano_flex',
        'team',
        'king_of_court',
        'just_padel_it',
        'round_robin',
        'escalera',
    ];

    public function code(): string { return 'formats_all'; }
    public function title(): string { return 'Знаток форматов'; }
    public function description(): string { return 'Сыграть все восемь форматов'; }
    public function icon(): string { return 'auto_awesome'; }
    public function group(): string { return 'variety'; }
    public function tier(): string { return 'gold'; }
    public function target(): int { return count(self::COUNTED); }

    public function progress(PlayerHistory $history): int
    {
        $types = [];
        foreach ($history->matches as $match) {
            $type = $match['tournament_type'] ?? null;
            if ($type && in_array($type, self::COUNTED, true)) {
                $types[$type] = true;
            }
        }

        return min($this->target(), count($types));
    }
}
