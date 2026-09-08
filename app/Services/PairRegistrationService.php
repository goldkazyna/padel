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
     * Посадить игрока на свободное место в паре.
     *
     * Организатор собирает пары до старта, и половина пары — обычное дело:
     * человек записался один. Раньше добить такую пару было нечем — только
     * разбить и собрать заново.
     */
    public function fillPair(Tournament $tournament, int $teamId, int $userId): array
    {
        if ($tournament->status !== 'open') {
            return [false, 'Турнир уже запущен или завершён'];
        }

        $user = User::find($userId);
        if (!$user) {
            return [false, 'Игрок не найден'];
        }

        $result = DB::transaction(function () use ($tournament, $teamId, $userId) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            $team = $tournament->teams()->whereKey($teamId)->lockForUpdate()->first();
            if (!$team) {
                return 'not_found';
            }
            if ($team->player2_id !== null) {
                return 'taken';
            }
            if ((int) $team->player1_id === $userId) {
                return 'self';
            }
            if ($this->alreadyPaired($tournament, [$userId])) {
                return 'in_pair';
            }

            // Записан ли он вообще: пару собирают и из тех, кого ещё нет в
            // составе, — тогда добавляем как обычного участника. А если он
            // висел на модерации или в листе ожидания — место в паре и есть
            // одобрение, иначе он играет, но в составе не числится.
            $active = $tournament->participants()
                ->wherePivotIn('status', ['registered', 'pending', 'waiting'])
                ->where('user_id', $userId)
                ->exists();

            if (!$active) {
                $tournament->participants()->wherePivot('status', 'cancelled')->detach($userId);
                $tournament->participants()->attach($userId, ['status' => 'registered']);
            } else {
                $tournament->participants()
                    ->wherePivotIn('status', ['pending', 'waiting'])
                    ->updateExistingPivot($userId, ['status' => 'registered']);
            }

            $partner = User::find($userId);
            $team->update([
                'player2_id' => $userId,
                'rating_avg' => (int) round(
                    ((int) $team->player1->rating + (int) $partner->rating) / 2
                ),
            ]);

            return 'filled';
        });

        return match ($result) {
            'not_found' => [false, 'Пара не найдена'],
            'taken' => [false, 'В этой паре уже двое'],
            'self' => [false, 'Игрок не может быть в паре с самим собой'],
            'in_pair' => [false, 'Игрок уже состоит в паре'],
            default => [true, "{$user->name} добавлен в пару"],
        };
    }

    /**
     * Перенести игрока в конкретную пару.
     *
     * Организатор тасует состав руками: «этого во вторую, того в четвёртую».
     * Из прежней пары игрок уходит сам — партнёр остаётся ждать нового.
     */
    public function movePlayerToPair(Tournament $tournament, int $userId, int $teamId): array
    {
        if ($tournament->status !== 'open') {
            return [false, 'Турнир уже запущен или завершён'];
        }

        $target = $tournament->teams()->whereKey($teamId)->first();
        if (!$target) {
            return [false, 'Пара не найдена'];
        }
        if ((int) $target->player1_id === $userId || (int) $target->player2_id === $userId) {
            return [false, 'Игрок уже в этой паре'];
        }
        if ($target->player2_id !== null) {
            return [false, 'В этой паре уже двое'];
        }

        \App\Support\OpenPairs::leave($tournament, $userId);

        return $this->fillPair($tournament->fresh(), $teamId, $userId);
    }

    /**
     * Посадить игрока в первое свободное место: сначала в чью-то неполную
     * пару, а если таких нет — открыть новую.
     */
    public function seatPlayer(Tournament $tournament, int $userId): array
    {
        $open = $tournament->teams()
            ->whereNull('player2_id')
            ->whereIn('status', ['approved', 'pending'])
            ->where('player1_id', '!=', $userId)
            ->orderBy('id')
            ->first();

        if ($open) {
            return $this->fillPair($tournament, $open->id, $userId);
        }

        if ($tournament->status !== 'open') {
            return [false, 'Турнир уже запущен или завершён'];
        }
        if ($this->alreadyPaired($tournament, [$userId])) {
            return [false, 'Игрок уже состоит в паре'];
        }

        $maxPairs = (int) floor($tournament->max_participants / 2);
        if ($tournament->teams()->whereIn('status', ['approved', 'pending'])->count() >= $maxPairs) {
            return [false, 'Пар больше, чем мест'];
        }

        $user = User::find($userId);
        \App\Models\TournamentTeam::create([
            'tournament_id' => $tournament->id,
            'player1_id' => $userId,
            'player2_id' => null,
            'status' => 'approved',
            'rating_avg' => (int) ($user?->rating ?? 0),
        ]);

        return [true, "{$user?->name} ждёт напарника в новой паре"];
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
