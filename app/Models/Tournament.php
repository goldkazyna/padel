<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\GameMatch;
use App\Models\TournamentPlayoffMatch;


class Tournament extends Model
{
    use HasFactory;

    /**
     * При удалении турнира зачищаем связанные RatingHistory-записи,
     * чтобы они не висели и не светились в карточках игроков.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $tournament) {
            \App\Models\RatingHistory::where('tournament_id', $tournament->id)->delete();
        });

        // Когда турнир переводят в статус cancelled — оповещаем всех
        // зарегистрированных участников: in-app уведомление + FCM push.
        static::updated(function (self $tournament) {
            if (
                $tournament->wasChanged('status')
                && $tournament->status === 'cancelled'
                && $tournament->getOriginal('status') !== 'cancelled'
            ) {
                \App\Http\Controllers\Api\MobileTournamentController::notifyParticipantsTournamentCancelled($tournament);
            }
        });
    }

    /**
     * Триггер #5 верификации: после завершения турнира пересчитать
     * level_verified у всех его участников. Если у юзера флаг
     * изменился — пишем запись в user_level_history (admin = $actorId).
     */
    public function recalculateParticipantsVerification(?int $actorId, ?int $clubId = null): void
    {
        $clubId = $clubId ?? $this->club_id;

        if ($this->type === 'team') {
            $playerIds = \App\Models\TournamentTeam::where('tournament_id', $this->id)
                ->where('status', 'approved')
                ->get(['player1_id', 'player2_id'])
                ->flatMap(fn($t) => [$t->player1_id, $t->player2_id])
                ->filter()
                ->unique()
                ->values();
        } else {
            $playerIds = $this->participants()
                ->wherePivotIn('status', ['registered', 'approved'])
                ->pluck('users.id')
                ->unique()
                ->values();
        }

        foreach ($playerIds as $userId) {
            $user = User::find($userId);
            if (!$user) continue;

            $oldLevel = $user->level !== null ? (float) $user->level : null;
            $oldVerified = (bool) $user->level_verified;
            if ($user->recomputeLevelVerified()) {
                \App\Models\UserLevelHistory::create([
                    'user_id' => $user->id,
                    'changed_by_user_id' => $actorId,
                    'club_id' => $clubId,
                    'old_level' => $oldLevel,
                    'new_level' => $oldLevel,
                    'old_verified' => $oldVerified,
                    'new_verified' => (bool) $user->level_verified,
                    'created_at' => now(),
                ]);
            }
        }
    }

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
		'courts',
		'courts_count',
		'has_lower_bracket',
		'has_bronze_match',
		'telegram_registration_url',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'registration_deadline' => 'datetime',
        'min_level' => 'decimal:2',
        'max_level' => 'decimal:2',
        'price' => 'decimal:2',
		'has_playoff' => 'boolean',
		'has_lower_bracket' => 'boolean',
		'has_bronze_match' => 'boolean',
		'courts' => 'array',
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
		return $this->takenSlotsCount() >= $this->max_participants;
	}

	/**
	 * Количество занятых мест (approved + pending)
	 */
	public function takenSlotsCount(): int
	{
		if ($this->isTeamBased()) {
			return $this->teams()->whereIn('status', ['approved', 'pending'])->count() * 2;
		}
		return $this->participants()->wherePivotIn('status', ['registered', 'pending'])->count();
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
			'king_of_court' => 'Король корта',
			'bali_koc' => 'Король Корта (Bali Format)',
			default => $this->type,
		};
	}
	public function isMexicano(): bool
	{
		return $this->type === 'mexicano';
	}

	public function isKingOfCourt(): bool
	{
		return $this->type === 'king_of_court';
	}

	public function kingOfCourtPlayers()
	{
		return $this->hasMany(KingOfCourtPlayer::class);
	}

	public function kingOfCourtRounds()
	{
		return $this->hasMany(KingOfCourtRound::class)->orderBy('round_number');
	}

	public function isBaliKoc(): bool
	{
		return $this->type === 'bali_koc';
	}

	public function baliKocPairs()
	{
		return $this->hasMany(BaliKocPair::class);
	}

	public function baliKocRounds()
	{
		return $this->hasMany(BaliKocRound::class)->orderBy('round_number');
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
     * Общее количество участников (registered + pending) с учётом типа турнира
     */
    public function totalParticipantsCount(): int
    {
        if ($this->isTeamBased()) {
            return $this->teams()->whereIn('status', ['approved', 'pending'])->count() * 2;
        }
        return $this->participants()->count();
    }

    /**
     * Количество одобренных
     */
    public function approvedParticipantsCount(): int
    {
        if ($this->isTeamBased()) {
            return $this->teams()->where('status', 'approved')->count() * 2;
        }
        return $this->participants()->wherePivot('status', 'registered')->count();
    }

    /**
     * Количество на модерации
     */
    public function pendingParticipantsCount(): int
    {
        if ($this->isTeamBased()) {
            return $this->teams()->where('status', 'pending')->count() * 2;
        }
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

		// Проверяем одиночных участников
		if ($this->participants()->whereIn('user_id', $reserveIds)->exists()) {
			return true;
		}

		// Проверяем командные пары (team турниры)
		if ($this->teams()
			->where(function ($q) use ($reserveIds) {
				$q->whereIn('player1_id', $reserveIds)
				  ->orWhereIn('player2_id', $reserveIds);
			})->exists()) {
			return true;
		}

		return false;
	}
	/**
	 * Количество кортов (автоматически)
	 */
	public function getCourtsCount(): int
	{
		return (int) ceil($this->max_participants / 4);
	}

	/**
	 * Название корта по номеру
	 */
	public function getCourtName(int $courtNumber): string
	{
		if ($this->courts && isset($this->courts[$courtNumber - 1])) {
			return $this->courts[$courtNumber - 1];
		}
		return "Корт {$courtNumber}";
	}
	public function isCancelled(): bool
	{
		return $this->status === 'cancelled';
	}

	public function subscriptions()
	{
		return $this->hasMany(TournamentSubscription::class);
	}
}