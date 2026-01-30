<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentGroup;
use App\Models\User;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    /**
     * Сформировать группы (без начала турнира)
     */
    public function generateGroups(Tournament $tournament)
    {
        if ($tournament->status !== 'open') {
            return back()->with('error', 'Турнир уже начат или завершён');
        }

        if ($tournament->type !== 'americano') {
            return back()->with('error', 'Формирование групп доступно только для Американо');
        }

        // Проверяем есть ли уже группы
        if ($tournament->groups()->count() > 0) {
            return back()->with('error', 'Группы уже сформированы');
        }

        // Получаем подтверждённых участников
		$participants = $tournament->participants()
			->wherePivot('status', 'registered')
			->inRandomOrder()
			->get();

		if ($participants->count() !== $tournament->max_participants) {
			return back()->with('error', "Нужно одобрить всех участников ({$participants->count()}/{$tournament->max_participants})");
		}

        $groupsCount = $tournament->groups_count ?? 2;
        $playersPerGroup = ceil($participants->count() / $groupsCount);

        // Создаём группы
        $groups = [];
        for ($i = 1; $i <= $groupsCount; $i++) {
            $groups[] = TournamentGroup::create([
                'tournament_id' => $tournament->id,
                'name' => 'Группа ' . $i,
            ]);
        }

        // Распределяем игроков по группам
        $groupIndex = 0;
        foreach ($participants as $index => $player) {
            $groups[$groupIndex]->players()->attach($player->id, [
                'total_points' => 0,
                'rating_before' => $player->rating,
            ]);

            // Переходим к следующей группе
            if (($index + 1) % $playersPerGroup === 0 && $groupIndex < $groupsCount - 1) {
                $groupIndex++;
            }
        }

        return back()->with('success', "Создано {$groupsCount} групп. Теперь можете отредактировать состав.");
    }

    /**
     * Удалить игрока из группы
     */
    public function removePlayerFromGroup(Tournament $tournament, TournamentGroup $group, User $player)
    {
        if ($tournament->status !== 'open') {
            return response()->json(['error' => 'Турнир уже начат'], 400);
        }

        $group->players()->detach($player->id);

        return response()->json(['success' => true, 'message' => 'Игрок удалён из группы']);
    }

    /**
     * Добавить игрока в группу
     */
    public function addPlayerToGroup(Request $request, Tournament $tournament, TournamentGroup $group)
    {
        if ($tournament->status !== 'open') {
            return response()->json(['error' => 'Турнир уже начат'], 400);
        }

        $playerId = $request->input('player_id');
        $player = User::findOrFail($playerId);

        // Проверяем что игрок зарегистрирован на турнир
        $isRegistered = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->where('user_id', $playerId)
            ->exists();

        if (!$isRegistered) {
            return response()->json(['error' => 'Игрок не зарегистрирован на турнир'], 400);
        }

        // Проверяем что игрок не в другой группе
        $inOtherGroup = $tournament->groups()
            ->whereHas('players', fn($q) => $q->where('user_id', $playerId))
            ->exists();

        if ($inOtherGroup) {
            return response()->json(['error' => 'Игрок уже в другой группе'], 400);
        }

        // Добавляем в группу
        $group->players()->attach($playerId, [
            'total_points' => 0,
            'rating_before' => $player->rating,
        ]);

        return response()->json(['success' => true, 'message' => 'Игрок добавлен в группу']);
    }

    /**
     * Сбросить группы
     */
    public function resetGroups(Tournament $tournament)
    {
        if ($tournament->status !== 'open') {
            return back()->with('error', 'Турнир уже начат');
        }

        // Удаляем всех игроков из групп
        foreach ($tournament->groups as $group) {
            $group->players()->detach();
        }

        // Удаляем группы
        $tournament->groups()->delete();

        return back()->with('success', 'Группы сброшены');
    }

    /**
     * Получить свободных игроков (не в группах)
     */
    public function getUnassignedPlayers(Tournament $tournament)
    {
        // Игроки которые в группах
        $assignedPlayerIds = $tournament->groups()
            ->with('players')
            ->get()
            ->pluck('players')
            ->flatten()
            ->pluck('id')
            ->toArray();

        // Зарегистрированные игроки которые не в группах
        $unassigned = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->whereNotIn('users.id', $assignedPlayerIds)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'level' => $p->level,
                'rating' => $p->rating,
            ]);

        return response()->json(['players' => $unassigned]);
    }
}