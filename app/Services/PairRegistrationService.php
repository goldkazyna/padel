<?php

namespace App\Services;

use App\Models\JustPadelItPair;
use App\Models\Tournament;
use App\Models\TournamentTeam;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Запись парой: организатор заводит сразу двоих.
 *
 * Пара ложится в одно из двух мест, и это зависит от турнира:
 *
 * - пары собирает организатор → в пары формата (just_padel_it_pairs);
 * - пары собирают сами игроки → в команды турнира, там уже есть модерация,
 *   лист ожидания и вывод в приложении.
 *
 * Разницу прячем здесь, чтобы веб и мобильная админка не разъезжались.
 */
class PairRegistrationService
{
    /** Пара ложится в пары формата, а не в команды турнира. */
    public function usesFormatPairs(Tournament $tournament): bool
    {
        return $tournament->isPairedJustPadelIt() && !$tournament->isSelfPairing();
    }

    /** Можно ли в этом турнире заводить пары. */
    public function supports(Tournament $tournament): bool
    {
        return $this->usesFormatPairs($tournament) || !$tournament->usesSoloRegistration();
    }

    /**
     * Состояние: собранные пары и те, кто записан, но пары не имеет.
     *
     * @return array{mode: string, pairs: array, unpaired: array}
     */
    public function state(Tournament $tournament): array
    {
        $format = $this->usesFormatPairs($tournament);

        $pairs = $format
            ? $tournament->justPadelItPairs()->with(['player1:id,name,phone', 'player2:id,name,phone'])->get()
            : $tournament->teams()->with(['player1:id,name,phone', 'player2:id,name,phone'])->get();

        $pairedIds = $pairs->flatMap(fn ($p) => [$p->player1_id, $p->player2_id])->unique();

        $unpaired = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->get(['users.id', 'users.name', 'users.phone'])
            ->reject(fn ($u) => $pairedIds->contains($u->id))
            ->values();

        return [
            'mode' => $format ? 'format' : 'teams',
            'pairs' => $pairs->map(fn ($p) => [
                'id' => $p->id,
                'status' => $p->status ?? 'approved',
                'player1' => ['id' => $p->player1_id, 'name' => $p->player1->name ?? '—'],
                'player2' => ['id' => $p->player2_id, 'name' => $p->player2->name ?? '—'],
            ])->values()->all(),
            'unpaired' => $unpaired->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
            ])->all(),
        ];
    }

    /**
     * Завести пару.
     *
     * @return array{0: bool, 1: string}
     */
    public function addPair(Tournament $tournament, int $player1Id, int $player2Id): array
    {
        if (!$this->supports($tournament)) {
            return [false, 'В этом турнире записываются поодиночке'];
        }
        if ($tournament->status !== 'open') {
            return [false, 'Турнир уже запущен или завершён'];
        }
        if ($player1Id === $player2Id) {
            return [false, 'Игрок не может быть в паре с самим собой'];
        }

        $ids = [$player1Id, $player2Id];
        if (User::whereIn('id', $ids)->count() !== 2) {
            return [false, 'Игрок не найден'];
        }

        $result = DB::transaction(function () use ($tournament, $ids) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            if ($this->alreadyPaired($tournament, $ids)) {
                return 'in_pair';
            }

            return $this->usesFormatPairs($tournament)
                ? $this->createFormatPair($tournament, $ids)
                : $this->createTeamPair($tournament, $ids);
        });

        if ($result === 'in_pair') {
            return [false, 'Один из игроков уже состоит в паре'];
        }
        if ($result === 'full') {
            return [false, 'Не хватает мест для новой пары'];
        }

        $names = User::whereIn('id', $ids)->pluck('name')->implode(' / ');

        return [true, "Пара добавлена: {$names}"];
    }

    /**
     * Разбить пару.
     *
     * Игроки остаются записанными — организатор может собрать их заново
     * с кем-то другим. Убрать совсем можно удалением участника.
     *
     * @return array{0: bool, 1: string}
     */
    public function removePair(Tournament $tournament, int $pairId): array
    {
        if ($tournament->status !== 'open') {
            return [false, 'Турнир уже запущен или завершён'];
        }

        if ($this->usesFormatPairs($tournament)) {
            $pair = JustPadelItPair::where('tournament_id', $tournament->id)->find($pairId);
            if (!$pair) {
                return [false, 'Пара не найдена'];
            }
            $pair->delete();

            return [true, 'Пара разбита, игроки остались в списке участников'];
        }

        $team = TournamentTeam::where('tournament_id', $tournament->id)->find($pairId);
        if (!$team) {
            return [false, 'Пара не найдена'];
        }

        $wasMain = in_array($team->status, ['approved', 'pending'], true);
        $team->delete();

        // Освободилось место — подтягиваем следующую пару из листа ожидания.
        if ($wasMain) {
            \App\Http\Controllers\Api\MobileTournamentController::promoteNextTeamFromWaitlist($tournament);
        }

        return [true, 'Пара удалена из турнира'];
    }

    /** @param array<int> $ids */
    private function alreadyPaired(Tournament $tournament, array $ids): bool
    {
        $relation = $this->usesFormatPairs($tournament)
            ? $tournament->justPadelItPairs()
            : $tournament->teams();

        return $relation
            ->where(function ($q) use ($ids) {
                $q->whereIn('player1_id', $ids)->orWhereIn('player2_id', $ids);
            })
            ->exists();
    }

    /** @param array<int> $ids */
    private function createFormatPair(Tournament $tournament, array $ids): string
    {
        // Уже записавшегося не привязываем повторно: он мог записаться сам
        // в приложении, а пару ему организатор подбирает вручную.
        $registered = $tournament->participants()->whereIn('users.id', $ids)->pluck('users.id')->all();
        $toAttach = array_values(array_diff($ids, $registered));

        if ($tournament->takenSlotsCount() + count($toAttach) > $tournament->max_participants) {
            return 'full';
        }

        foreach ($toAttach as $id) {
            $tournament->participants()->attach($id, ['status' => 'registered']);
        }

        JustPadelItPair::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $ids[0],
            'player2_id' => $ids[1],
        ]);

        return 'ok';
    }

    /** @param array<int> $ids */
    private function createTeamPair(Tournament $tournament, array $ids): string
    {
        $maxTeams = (int) ($tournament->max_participants / 2);
        if ($tournament->teams()->count() >= $maxTeams) {
            return 'full';
        }

        $ratings = User::whereIn('id', $ids)->pluck('rating', 'id');

        TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $ids[0],
            'player2_id' => $ids[1],
            'rating_avg' => (int) ((($ratings[$ids[0]] ?? 0) + ($ratings[$ids[1]] ?? 0)) / 2),
        ]);

        return 'ok';
    }
}
