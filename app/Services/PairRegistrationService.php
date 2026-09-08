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
                // Из листа ожидания поднимаем: место в паре — это состав.
                // Заявку на модерации не одобряем: это отдельное решение,
                // а место за ней в открытых парах и так держится.
                $tournament->participants()
                    ->wherePivotIn('status', ['waiting'])
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
        return $this->movePlayerToSeat($tournament, $userId, $teamId, 2);
    }

    /**
     * Пересадить игрока на конкретное место.
     *
     * Организатор тасует состав как хочет: свободное место, занятое (тогда
     * игроки меняются местами) и пустая пара — $teamId = 0. Раньше пересадить
     * можно было только в пару со свободным местом, и когда все пары полные,
     * меню оказывалось пустым: приходилось разбивать пару руками.
     *
     * $seat — 1 или 2. Первое место всегда занято: пары без первого игрока
     * не бывает.
     */
    public function movePlayerToSeat(Tournament $tournament, int $userId, int $teamId, int $seat = 2): array
    {
        if ($tournament->status !== 'open') {
            return [false, 'Турнир уже запущен или завершён'];
        }
        if (!in_array($seat, [1, 2], true)) {
            return [false, 'Неизвестное место в паре'];
        }

        $user = User::find($userId);
        if (!$user) {
            return [false, 'Игрок не найден'];
        }

        $outcome = DB::transaction(function () use ($tournament, $userId, $teamId, $seat) {
            Tournament::where('id', $tournament->id)->lockForUpdate()->first();

            $from = \App\Support\OpenPairs::teamOf($tournament, $userId);

            if ($teamId === 0) {
                if ($from && $from->player2_id === null) {
                    return 'alone';
                }
                if (!\App\Support\OpenPairs::canCreatePair($tournament)) {
                    return 'no_room';
                }

                $this->releaseSeat($from, $userId);
                \App\Models\TournamentTeam::create([
                    'tournament_id' => $tournament->id,
                    'player1_id' => $userId,
                    'player2_id' => null,
                    'status' => 'approved',
                    'rating_avg' => (int) (User::find($userId)?->rating ?? 0),
                ]);
                $this->seatIsRoster($tournament, $userId);

                return 'moved';
            }

            $target = $tournament->teams()->whereKey($teamId)->lockForUpdate()->first();
            if (!$target) {
                return 'not_found';
            }

            $column = $seat === 1 ? 'player1_id' : 'player2_id';
            $occupant = $target->$column ? (int) $target->$column : null;
            if ($occupant === $userId) {
                return 'same';
            }

            // Внутри своей же пары — просто меняем игроков местами.
            if ($from && (int) $from->id === (int) $target->id) {
                if ($target->player2_id === null) {
                    return 'same';
                }
                $target->update([
                    'player1_id' => $target->player2_id,
                    'player2_id' => $target->player1_id,
                ]);

                return 'moved';
            }

            if ($occupant !== null && $from) {
                // Обмен между парами: каждый занимает место другого.
                $fromColumn = (int) $from->player1_id === $userId ? 'player1_id' : 'player2_id';
                $from->update([$fromColumn => $occupant]);
                $target->update([$column => $userId]);
                $this->syncPair($from);
            } elseif ($occupant !== null) {
                // Игрок был без пары — тот, кого он сменил, уходит в «Без пары».
                $target->update([$column => $userId]);
            } else {
                $this->releaseSeat($from, $userId);
                $target->update([$column => $userId]);
            }

            $this->syncPair($target);
            $this->seatIsRoster($tournament, $userId);

            return 'moved';
        });

        return match ($outcome) {
            'not_found' => [false, 'Пара не найдена'],
            'same' => [false, 'Игрок уже на этом месте'],
            'alone' => [false, 'Игрок и так один в паре'],
            'no_room' => [false, 'Пар больше, чем мест'],
            default => [true, "{$user->name} пересажен"],
        };
    }

    /**
     * Освободить место игрока в его прежней паре: остался напарник — пара
     * снова открыта, не осталось никого — пары нет.
     */
    private function releaseSeat($team, int $userId): void
    {
        if (!$team) {
            return;
        }

        $isFirst = (int) $team->player1_id === $userId;
        $partnerId = $isFirst ? $team->player2_id : $team->player1_id;

        if (!$partnerId) {
            $team->delete();

            return;
        }

        $team->update(['player1_id' => $partnerId, 'player2_id' => null]);
        $this->syncPair($team);
    }

    /**
     * Пересчитать средний рейтинг пары и убрать её, если игроков не осталось.
     */
    private function syncPair($team): void
    {
        if (!$team) {
            return;
        }

        $team->refresh();

        if (!$team->player1_id && !$team->player2_id) {
            $team->delete();

            return;
        }
        if (!$team->player1_id) {
            $team->player1_id = $team->player2_id;
            $team->player2_id = null;
        }

        $first = (int) (User::find($team->player1_id)?->rating ?? 0);
        $second = $team->player2_id ? (int) (User::find($team->player2_id)?->rating ?? 0) : null;

        $team->rating_avg = $second === null ? $first : (int) round(($first + $second) / 2);
        $team->save();
    }

    /**
     * Место в паре — это состав. Из листа ожидания поднимаем, кого в турнире
     * нет вовсе — записываем; заявку на модерации не трогаем: в открытых
     * парах место за ней и так держится, а одобрение — отдельное решение.
     */
    private function seatIsRoster(Tournament $tournament, int $userId): void
    {
        $status = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending', 'waiting'])
            ->where('user_id', $userId)
            ->first()?->pivot?->status;

        if ($status === null) {
            $tournament->participants()->wherePivot('status', 'cancelled')->detach($userId);
            $tournament->participants()->attach($userId, ['status' => 'registered']);

            return;
        }

        if ($status === 'waiting') {
            $tournament->participants()->updateExistingPivot($userId, ['status' => 'registered']);
        }
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
