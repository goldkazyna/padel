<?php

namespace App\Services;

use App\Models\AmericanoFlexRound;
use App\Models\BaliKocRound;
use App\Models\JustPadelItRound;
use App\Models\KingOfCourtRound;
use App\Models\MexicanoRound;
use App\Models\RoundRobinRound;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Model;

/**
 * Удаление лишнего раунда.
 *
 * Раунд генерируется кнопкой, и его легко нажать лишний раз: турнир
 * закончился, а в таблице висит пустой десятый раунд, и завершить турнир
 * нельзя. Форматы с плоским списком раундов устроены одинаково, поэтому
 * правила и проверки живут в одном месте.
 *
 * Удалять можно ТОЛЬКО последний раунд и только пока в нём нет счёта:
 * середину вынимать нельзя — развалится нумерация и ротация, а сыгранное
 * уже посчитано в таблице и в рейтинге.
 */
class RoundRemovalService
{
    /** Тип турнира → класс модели раунда. */
    private const ROUNDS = [
        'just_padel_it' => JustPadelItRound::class,
        'king_of_court' => KingOfCourtRound::class,
        'americano_flex' => AmericanoFlexRound::class,
        'round_robin' => RoundRobinRound::class,
        'mexicano' => MexicanoRound::class,
        'bali_koc' => BaliKocRound::class,
    ];

    /** Поддерживает ли формат удаление раундов. */
    public function supports(Tournament $tournament): bool
    {
        return isset(self::ROUNDS[$tournament->type]);
    }

    /**
     * Можно ли удалить этот раунд.
     *
     * @return array{0: bool, 1: string} второй элемент — причина отказа
     */
    public function check(Tournament $tournament, Model $round): array
    {
        if (!$this->supports($tournament)) {
            return [false, 'Для этого формата удаление раундов не поддерживается'];
        }

        if ((int) $round->tournament_id !== (int) $tournament->id) {
            return [false, 'Раунд не из этого турнира'];
        }

        if ($tournament->status === 'completed') {
            return [false, 'Турнир завершён — раунды менять нельзя'];
        }

        $last = $this->lastRound($tournament);
        if (!$last || (int) $last->id !== (int) $round->id) {
            return [false, 'Удалить можно только последний раунд: середину вынимать нельзя'];
        }

        $played = $round->matches()->whereNotNull('team1_score')->count()
            + $round->matches()->where('status', 'completed')->count();

        if ($played > 0) {
            return [false, 'В раунде уже есть счёт — сначала уберите результаты'];
        }

        return [true, ''];
    }

    /**
     * Удалить раунд вместе с его матчами.
     *
     * @return array{0: bool, 1: string}
     */
    public function remove(Tournament $tournament, Model $round): array
    {
        [$can, $reason] = $this->check($tournament, $round);
        if (!$can) {
            return [false, $reason];
        }

        $number = (int) $round->round_number;

        $round->matches()->delete();
        $round->delete();

        // Предыдущий раунд снова становится текущим: иначе кнопка
        // «следующий раунд» и завершение турнира смотрят в пустоту.
        $previous = $this->lastRound($tournament);
        if ($previous && $previous->status !== 'completed') {
            $previous->update(['status' => 'in_progress']);
        }

        return [true, "Раунд {$number} удалён"];
    }

    /** Последний по номеру раунд турнира. */
    public function lastRound(Tournament $tournament): ?Model
    {
        $class = self::ROUNDS[$tournament->type] ?? null;
        if (!$class) {
            return null;
        }

        return $class::where('tournament_id', $tournament->id)
            ->orderByDesc('round_number')
            ->orderByDesc('id')
            ->first();
    }

    /** Модель раунда по типу турнира и id — для контроллеров. */
    public function findRound(Tournament $tournament, int $roundId): ?Model
    {
        $class = self::ROUNDS[$tournament->type] ?? null;

        return $class ? $class::find($roundId) : null;
    }
}
