<?php

namespace App\Services;

use App\Models\League;
use App\Models\LeaguePlayer;
use App\Models\Tournament;

/**
 * Общая логика лиг для веб-CRM и мобильной админки.
 *
 * Правила создания этапа и наполнения состава должны совпадать в обоих
 * интерфейсах: организатор заводит лигу с телефона, а доводит с компьютера.
 */
class LeagueService
{
    /**
     * Создать этап — обычный турнир Americano Flex с настройками лиги.
     *
     * @param array{name?: ?string, start_date: string, max_participants: int,
     *              price?: ?int, courts_count?: ?int} $data
     */
    public function createStage(League $league, array $data, ?int $creatorId = null): Tournament
    {
        $stage = $league->nextStageNumber();

        $tournament = Tournament::create([
            'club_id' => $league->club_id,
            'league_id' => $league->id,
            'league_stage' => $stage,
            'creator_id' => $creatorId,
            'name' => ($data['name'] ?? null) ?: "{$league->name} — этап {$stage}",
            'type' => 'americano_flex',
            'status' => 'open',
            'start_date' => $data['start_date'],
            'min_level' => $league->min_level ?? 1,
            'max_level' => $league->max_level ?? 7,
            'max_participants' => $data['max_participants'],
            // Цена в турнире обязательна: берём из этапа, потом из лиги.
            'price' => $data['price'] ?? $league->price ?? 0,
            // Формат этапов задаётся один раз в лиге: все восемь вечеров
            'courts_count' => $data['courts_count'] ?? $league->courts_count ?? 2,
            'is_paired' => (bool) $league->is_paired,
            'duration_hours' => $league->duration_hours,
            // В турнире у points_to_win есть значение по умолчанию и NOT NULL,
            // поэтому пустую настройку лиги не передаём вовсе.
            ...($league->points_to_win ? ['points_to_win' => $league->points_to_win] : []),
            'verified_only' => (bool) $league->verified_only,
            'chat_enabled' => (bool) $league->chat_enabled,
            'is_rated' => $league->is_rated,
        ]);

        $this->fillFromLeague($league, $tournament);

        // Первый созданный этап переводит лигу из «открыта» в «идёт».
        if ($league->status === 'open') {
            $league->update(['status' => 'in_progress']);
        }

        return $tournament;
    }

    /**
     * Записать состав лиги в этап.
     *
     * Люди записывались в лигу целиком, а не в каждый турнир: иначе перед
     * каждым этапом организатору пришлось бы собирать состав заново.
     */
    public function fillFromLeague(League $league, Tournament $tournament): void
    {
        $userIds = $league->activePlayers()->pluck('user_id');
        if ($userIds->isEmpty()) {
            return;
        }

        $rows = $userIds->take($tournament->max_participants)
            ->mapWithKeys(fn ($id) => [$id => [
                'status' => 'registered',
                'created_at' => now(),
                'updated_at' => now(),
            ]])
            ->all();

        $tournament->participants()->syncWithoutDetaching($rows);
    }

    /**
     * Удалить этап лиги.
     *
     * Завершённый этап удалять нельзя: его очки уже в сводной таблице, и
     * удаление переписало бы историю лиги задним числом. Незавершённый
     * уносит с собой раунды и матчи — они каскадные.
     *
     * @return bool false, если этап завершён
     */
    public function deleteStage(League $league, Tournament $stage): bool
    {
        if ($stage->status === 'completed') {
            return false;
        }

        $stage->delete();
        $this->renumberStages($league);

        return true;
    }

    /**
     * Пересчитать номера этапов подряд.
     *
     * Иначе после удаления второго из трёх остаются этапы 1 и 3, и
     * «этап 3 из 8» перестаёт совпадать со списком. Переименовываем только
     * названия, которые сгенерировали сами: свои названия организатора
     * трогать нельзя.
     */
    public function renumberStages(League $league): void
    {
        $number = 1;

        foreach ($league->stages()->orderBy('league_stage')->orderBy('id')->get() as $stage) {
            $wasGenerated = $stage->name === "{$league->name} — этап {$stage->league_stage}";

            $stage->update([
                'league_stage' => $number,
                'name' => $wasGenerated ? "{$league->name} — этап {$number}" : $stage->name,
            ]);

            $number++;
        }
    }

    public function addPlayer(League $league, int $userId): LeaguePlayer
    {
        return LeaguePlayer::updateOrCreate(
            ['league_id' => $league->id, 'user_id' => $userId],
            ['status' => 'registered', 'joined_at' => now(), 'left_at' => null]
        );
    }

    /**
     * Убрать игрока из состава.
     *
     * Запись остаётся со статусом «выбыл»: очки в сыгранных этапах никуда не
     * деваются, и история лиги не переписывается задним числом.
     */
    public function removePlayer(League $league, int $userId): void
    {
        LeaguePlayer::where('league_id', $league->id)
            ->where('user_id', $userId)
            ->update(['status' => 'left', 'left_at' => now()]);
    }
}
