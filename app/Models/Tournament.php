<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\GameMatch;
use App\Models\TournamentPlayoffMatch;


class Tournament extends Model
{
    use HasFactory;
	    // Форматы плей-офф
    const PLAYOFF_FORMAT_MIX = 'mix';
    const PLAYOFF_FORMAT_GROUP_VS = 'group_vs';
    const PLAYOFF_FORMAT_TOPS = 'tops';
    const PLAYOFF_FORMAT_CROSS = 'cross';
	
    protected $fillable = [
        'club_id',
        'name',
        'description',
        'start_date',
        'registration_deadline',
        'min_level',
        'max_level',
        'max_participants',
        'price',
        'status',
		'type',
		'points_to_win',
		'groups_count',
		'rounds_count',
		'teams_advance',
		'has_playoff',
		'playoff_type',
		'playoff_format',
		'reserve_count',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'registration_deadline' => 'datetime',
        'min_level' => 'decimal:2',
        'max_level' => 'decimal:2',
        'price' => 'decimal:2',
		'has_playoff' => 'boolean',
    ];

    // Связи
    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'tournament_participants')
                    ->withPivot('status')
                    ->withTimestamps();
    }

    // Проверки статуса
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isFull(): bool
	{
		return $this->approvedParticipantsCount() >= $this->max_participants;
	}

    public function canRegister(User $user): bool
    {
        // Турнир открыт
        if (!$this->isOpen()) return false;
        
        // Не переполнен
        if ($this->isFull()) return false;
        
        
        // Уровень подходит
        if ($user->level < $this->min_level || $user->level > $this->max_level) return false;
        
        // Ещё не зарегистрирован
        if ($this->participants()->where('user_id', $user->id)->exists()) return false;
        
        return true;
    }
	/**
	 * Причина почему нельзя зарегистрироваться
	 */
	public function getRegistrationBlockReason(User $user): ?string
	{
		if ($this->isRegistered($user)) {
			return null; // Уже зарегистрирован - не блок
		}
		
		if (!$this->isOpen()) {
			return 'Турнир не открыт для регистрации';
		}
		
		if ($this->isFull()) {
			return 'Все места заняты';
		}
		
		if ($user->level < $this->min_level) {
			return 'Ваш уровень (' . $user->level . ') ниже минимального (' . $this->min_level . ')';
		}
		
		if ($user->level > $this->max_level) {
			return 'Ваш уровень (' . $user->level . ') выше максимального (' . $this->max_level . ')';
		}
		
		return null;
	}
    public function isRegistered(User $user): bool
    {
        return $this->participants()->where('user_id', $user->id)->exists();
    }

    // Статус текстом
    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'draft' => 'Черновик',
            'open' => 'Открыта регистрация',
            'closed' => 'Регистрация закрыта',
            'in_progress' => 'Идёт турнир',
            'completed' => 'Завершён',
            'cancelled' => 'Отменён',
            default => $this->status,
        };
    }

    // Цвет статуса для badge
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'draft' => 'secondary',
            'open' => 'success',
            'closed' => 'warning',
            'in_progress' => 'primary',
            'completed' => 'info',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }
	public function matches()
	{
		return $this->hasMany(GameMatch::class);
	}
	public function groups()
	{
		return $this->hasMany(TournamentGroup::class);
	}

	public function isAmericano(): bool
	{
		return $this->type === 'americano';
	}

	public function isClassic(): bool
	{
		return $this->type === 'classic';
	}

	public function getTypeNameAttribute(): string
	{
		return match($this->type) {
			'americano' => 'Американо',
			'mexicano' => 'Мексикано',
			'team' => 'Групповой + Плей-офф',
			'classic' => 'Классический',
			default => $this->type,
		};
	}
	public function isMexicano(): bool
	{
		return $this->type === 'mexicano';
	}

	public function mexicanoPlayers()
	{
		return $this->hasMany(MexicanoPlayer::class);
	}

	public function mexicanoRounds()
	{
		return $this->hasMany(MexicanoRound::class)->orderBy('round_number');
	}

	public function mexicanoPairHistory()
	{
		return $this->hasMany(MexicanoPairHistory::class);
	}
	public function isTeamBased(): bool
	{
		return $this->type === 'team';
	}

	public function teams()
	{
		return $this->hasMany(TournamentTeam::class);
	}

	public function teamGroups()
	{
		return $this->hasMany(TournamentTeamGroup::class);
	}

	public function playoffMatches()
	{
		return $this->hasMany(TournamentPlayoffMatch::class);
	}
	/**
     * Одобренные участники
     */
    public function approvedParticipants()
    {
        return $this->belongsToMany(User::class, 'tournament_participants')
                    ->withPivot('status')
                    ->withTimestamps()
                    ->wherePivot('status', 'registered');
    }

    /**
     * Заявки на модерации
     */
    public function pendingParticipants()
    {
        return $this->belongsToMany(User::class, 'tournament_participants')
                    ->withPivot('status')
                    ->withTimestamps()
                    ->wherePivot('status', 'pending');
    }

    /**
     * Количество одобренных
     */
    public function approvedParticipantsCount(): int
    {
        return $this->participants()->wherePivot('status', 'registered')->count();
    }

    /**
     * Количество на модерации
     */
    public function pendingParticipantsCount(): int
    {
        return $this->participants()->wherePivot('status', 'pending')->count();
    }

    /**
     * Получить статус участника
     */
    public function getParticipantStatus(User $user): ?string
    {
        $participant = $this->participants()->where('user_id', $user->id)->first();
        return $participant ? $participant->pivot->status : null;
    }
	
	/**
	 * Есть ли плей-офф в турнире
	 */
	public function hasPlayoff(): bool
	{
		return $this->has_playoff === true;
	}

	/**
	 * Только финал
	 */
	public function isFinalOnly(): bool
	{
		return $this->playoff_type === 'final_only';
	}

	/**
	 * Полуфинал + финал
	 */
	public function isSemifinalFinal(): bool
	{
		return $this->playoff_type === 'semifinal_final';
	}

	/**
	 * Можно ли выбрать полуфинал (2+ группы)
	 */
	public function canHaveSemifinal(): bool
	{
		return $this->groups_count >= 2;
	}
	public static function playoffFormats(): array
    {
        return [
            self::PLAYOFF_FORMAT_MIX => 'Микс (A1+B2 vs A3+B4)',
            self::PLAYOFF_FORMAT_GROUP_VS => 'Группа vs Группа (A1+A2 vs B1+B2)',
            self::PLAYOFF_FORMAT_TOPS => 'Топы вместе (A1+B1 vs A3+B3)',
            self::PLAYOFF_FORMAT_CROSS => 'Крест (A1+B4 vs B1+A4)',
        ];
    }

    public function getPlayoffFormatName(): string
    {
        return self::playoffFormats()[$this->playoff_format] ?? 'Микс';
    }
	/**
	 * Есть ли резервы среди участников
	 */
	public function hasReserveParticipants(): bool
	{
		$reserveIds = \App\Models\User::where('role', 'reserve')->pluck('id');
		return $this->participants()->whereIn('user_id', $reserveIds)->exists();
	}

}