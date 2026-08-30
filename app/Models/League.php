<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Лига — серия турниров с общей таблицей.
 *
 * Этапы лиги остаются обычными турнирами: у них своё проведение, счёт,
 * рейтинг и чат. Лига добавляет общий состав и сводный зачёт.
 */
class League extends Model
{
    protected $fillable = [
        'club_id', 'creator_id', 'name', 'description', 'cover',
        'status', 'format', 'stages_planned',
        'is_paired', 'courts_count', 'duration_hours', 'points_to_win',
        'verified_only', 'chat_enabled',
        'start_date', 'end_date',
        'min_level', 'max_level', 'max_players', 'price', 'is_rated',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'min_level' => 'decimal:2',
        'max_level' => 'decimal:2',
        'is_rated' => 'boolean',
        'is_paired' => 'boolean',
        'verified_only' => 'boolean',
        'chat_enabled' => 'boolean',
    ];

    public const STATUSES = [
        'draft' => 'Черновик',
        'open' => 'Открыта регистрация',
        'in_progress' => 'Идёт',
        'completed' => 'Завершена',
        'cancelled' => 'Отменена',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** Этапы лиги — обычные турниры, упорядоченные по номеру этапа. */
    public function stages()
    {
        return $this->hasMany(Tournament::class)->orderBy('league_stage');
    }

    public function players()
    {
        return $this->hasMany(LeaguePlayer::class);
    }

    /** Состав лиги: те, кто в ней сейчас. */
    public function activePlayers()
    {
        return $this->players()->where('status', 'registered');
    }

    public function getStatusNameAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Сколько этапов уже сыграно — по ним и считается таблица. */
    public function finishedStagesCount(): int
    {
        return $this->stages()->where('status', 'completed')->count();
    }

    /** Следующий свободный номер этапа. */
    public function nextStageNumber(): int
    {
        return (int) $this->stages()->max('league_stage') + 1;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'open', 'in_progress'], true);
    }
}
