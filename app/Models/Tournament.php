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
        // Колонка pairing_mode заведена под групповой турнир, и её умолчание —
        // «self». Для парного Just Padel It правильное умолчание обратное: пары
        // собирает админ, так формат работал до появления выбора. Ставим его
        // здесь, а не в контроллерах, чтобы любой способ создания турнира
        // (сидер, команда, тест) получал безопасное поведение.
        static::creating(function (self $tournament) {
            if (
                $tournament->type === 'just_padel_it'
                && $tournament->is_paired
                && !array_key_exists('pairing_mode', $tournament->getAttributes())
            ) {
                $tournament->pairing_mode = 'admin';
            }
        });

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

            // Изменение даты турнира — оповещаем записанных участников
            // (кроме отменённых/завершённых турниров).
            if (
                $tournament->wasChanged('start_date')
                && ! in_array($tournament->status, ['cancelled', 'completed'], true)
            ) {
                \App\Http\Controllers\Api\MobileTournamentController::notifyParticipantsTournamentDateChanged($tournament);
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

        // Авто-верификация только у клубов с правом на пользователей/уровни.
        $club = $clubId ? \App\Models\Club::find($clubId) : null;
        if (!$club || !$club->canVerifyLevels()) {
            return;
        }

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
    const PLAYOFF_FORMAT_TOP_BOTTOM = 'top_bottom';
    const PLAYOFF_FORMAT_TABLE_QF = 'table_qf';
	
    protected $fillable = [
        'club_id',
        'venue_club_id',
        'creator_id',
        'name',
        'description',
        'has_prizes',
        'prizes',
        'jpi_rank_by_wins',
        'start_date',
        'duration_hours',
        'registration_deadline',
        'min_level',
        'max_level',
        'max_participants',
        'price',
        'status',
        'is_rated',
		'verified_only',
		'round_robin_schedule',
		'type',
		'points_to_win',
		'groups_count',
		'rounds_count',
		'teams_advance',
		'pairing_mode',
		'is_paired',
		'has_playoff',
		'playoff_type',
		'playoff_format',
		'reserve_count',
		'waitlist_size',
		'moderation_hours',
		'moderation_minutes',
		'courts',
		'courts_count',
		'has_lower_bracket',
		'has_bronze_match',
		'telegram_registration_url',
		'chat_enabled',
		'chat_write_mode',
		'escalera_standings_mode',
    ];

    /**
     * Зачёт эскалеры по умолчанию — по сумме забитых очков.
     * Дефолт задан здесь, а не в миграции: колонка enum, и менять её
     * значение по умолчанию отдельной миграцией дороже, чем оно того стоит.
     */
    protected $attributes = [
        'escalera_standings_mode' => 'raw_points',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'registration_deadline' => 'datetime',
        'min_level' => 'decimal:2',
        'max_level' => 'decimal:2',
        'price' => 'decimal:2',
		'has_playoff' => 'boolean',
		'has_prizes' => 'boolean',
		'jpi_rank_by_wins' => 'boolean',
		'has_lower_bracket' => 'boolean',
		'has_bronze_match' => 'boolean',
		'is_rated' => 'boolean',
		'is_paired' => 'boolean',
		'verified_only' => 'boolean',
		'chat_enabled' => 'boolean',
		'round_robin_schedule' => 'array',
		'courts' => 'array',
		'moderation_hours' => 'integer',
		'moderation_minutes' => 'integer',
    ];

    /**
     * Личный (приватный) турнир обычного игрока: создан не от клуба, а от
     * пользователя. В публичной вкладке не показывается, всегда нерейтинговый.
     */
    public function isPersonal(): bool
    {
        return $this->creator_id !== null;
    }

    /**
     * Создатель личного турнира (или null для клубных).
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Турнир, где пары собирает админ, а игроки записываются поодиночке.
     */
    public function isAdminPairing(): bool
    {
        // Парный Americano Flex собирает только админ — выбора там нет.
        if ($this->isPairedFlex()) {
            return true;
        }

        return $this->supportsPairingModeChoice() && $this->pairing_mode === 'admin';
    }

    /**
     * Форматы, где клуб выбирает, кто собирает пары: групповой и парный JPI.
     * У остальных выбора нет — либо пар нет вовсе, либо их собирает только админ.
     */
    public function supportsPairingModeChoice(): bool
    {
        return $this->type === 'team' || $this->isPairedJustPadelIt();
    }

    /**
     * Игроки записываются парой сами (вводят партнёра при регистрации).
     */
    public function isSelfPairing(): bool
    {
        return $this->supportsPairingModeChoice() && !$this->isAdminPairing();
    }

    /**
     * Парный Americano Flex (фиксированные пары, ротация соперников и отдыха).
     */
    public function isPairedFlex(): bool
    {
        return $this->type === 'americano_flex' && (bool) $this->is_paired;
    }

    /**
     * Регистрация одиночная (а не парная): любой тип кроме team-с-самосбором.
     * Используется на фазе регистрации (счётчик, детали, can_register).
     * Парный флекс — тоже одиночная регистрация (пары собирает админ).
     */
    public function usesSoloRegistration(): bool
    {
        // Парой записываются только там, где есть выбор способа и выбран «сами
        // игроки». Во всех остальных случаях — поодиночке.
        return !$this->isSelfPairing();
    }

    // Связи
    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    // Клуб-площадка (где играем) — необязательный, отдельно от организатора.
    public function venueClub()
    {
        return $this->belongsTo(Club::class, 'venue_club_id');
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'tournament_participants')
                    ->withPivot('status', 'attended_at', 'moderation_deadline', 'reminder_sent_at', 'reminded_1d_at', 'reminded_2h_at', 'reminded_1h_at')
                    ->withTimestamps();
    }

    /** Окно модерации в минутах: moderation_minutes имеет приоритет, иначе moderation_hours*60. 0 = выключен. */
    public function moderationWindowMinutes(): int
    {
        $min = (int) ($this->moderation_minutes ?? 0);
        if ($min > 0) return $min;
        return (int) ($this->moderation_hours ?? 0) * 60;
    }

    /** Дедлайн модерации для новой pending-заявки (или null, если таймер выключен). */
    public function moderationDeadline(): ?\Carbon\Carbon
    {
        $minutes = $this->moderationWindowMinutes();
        return $minutes > 0 ? now()->addMinutes($minutes) : null;
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
	 * Количество занятых мест (approved + pending, без waiting)
	 */
	public function takenSlotsCount(): int
	{
		if (!$this->usesSoloRegistration()) {
			return $this->teams()->whereIn('status', ['approved', 'pending'])->count() * 2;
		}
		return $this->participants()->wherePivotIn('status', ['registered', 'pending'])->count();
	}

	/**
	 * Сколько уже стоит в листе ожидания (в людях; для team пары*2)
	 */
	public function waitlistCount(): int
	{
		if (!$this->usesSoloRegistration()) {
			return $this->teams()->where('status', 'waiting')->count() * 2;
		}
		return $this->participants()->wherePivot('status', 'waiting')->count();
	}

	/**
	 * Есть ли свободное место в листе ожидания.
	 * $needSlots: 1 для solo, 2 для solo-с-другом, 2 для team (одна пара).
	 */
	public function hasWaitlistSlot(int $needSlots = 1): bool
	{
		$size = (int) ($this->waitlist_size ?? 0);
		if ($size <= 0) return false;
		// waitlist_size для team задаётся в парах, для solo в людях.
		$capacity = !$this->usesSoloRegistration() ? $size * 2 : $size;
		return ($this->waitlistCount() + $needSlots) <= $capacity;
	}

	/**
	 * Позиция пользователя в листе ожидания (1-based), либо null
	 */
	public function getWaitlistPosition(User $user): ?int
	{
		if (!$this->usesSoloRegistration()) {
			$teams = $this->teams()->where('status', 'waiting')
				->orderBy('created_at')->orderBy('id')->get(['id', 'player1_id', 'player2_id']);
			$pos = 1;
			foreach ($teams as $t) {
				if ($t->player1_id === $user->id || $t->player2_id === $user->id) return $pos;
				$pos++;
			}
			return null;
		}
		$rows = $this->participants()->wherePivot('status', 'waiting')
			->orderBy('tournament_participants.created_at')
			->get(['users.id']);
		$pos = 1;
		foreach ($rows as $r) {
			if ($r->id === $user->id) return $pos;
			$pos++;
		}
		return null;
	}

    public function canRegister(User $user): bool
    {
        // Турнир открыт
        if (!$this->isOpen()) return false;
        
        // Не переполнен
        if ($this->isFull()) return false;

        // Только для верифицированных игроков
        if ($this->verified_only && !$user->level_verified) return false;

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

		if ($this->verified_only && !$user->level_verified) {
			return 'Турнир только для верифицированных игроков';
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
			'americano_flex' => 'Americano Flex',
			'round_robin' => 'Round Robin',
			'just_padel_it' => 'Just Padel It',
			'escalera' => 'Ladder',
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

	public function kingOfCourtPairs()
	{
		return $this->hasMany(KingOfCourtPair::class);
	}

	/** «Король корта» с фиксированными парами. */
	public function isPairedKingOfCourt(): bool
	{
		return $this->isKingOfCourt() && (bool) $this->is_paired;
	}

	public function isJustPadelIt(): bool
	{
		return $this->type === 'just_padel_it';
	}

	public function isEscalera(): bool
	{
		return $this->type === 'escalera';
	}

	public function isPairedJustPadelIt(): bool
	{
		return $this->isJustPadelIt() && (bool) $this->is_paired;
	}

	/**
	 * Готов ли solo Just Padel It к посеву: зарегистрированных игроков должно
	 * быть ровно courts_count × 4 (иначе не разложить по кортам). Если корты
	 * не заданы — достаточно кратности 4 (и не меньше 4).
	 */
	public function jpiSeedingReady(): bool
	{
		if (! $this->isJustPadelIt() || (bool) $this->is_paired) {
			return false;
		}
		$count = $this->participants()->wherePivot('status', 'registered')->count();
		$courts = (int) $this->courts_count;

		return $courts > 0
			? $count === $courts * 4
			: ($count >= 4 && $count % 4 === 0);
	}

	public function justPadelItPlayers()
	{
		return $this->hasMany(\App\Models\JustPadelItPlayer::class);
	}

	public function justPadelItRounds()
	{
		return $this->hasMany(\App\Models\JustPadelItRound::class)->orderBy('round_number');
	}

	public function justPadelItPairs()
	{
		return $this->hasMany(\App\Models\JustPadelItPair::class);
	}

	public function escaleraPlayers()
	{
		return $this->hasMany(\App\Models\EscaleraPlayer::class);
	}

	public function escaleraRounds()
	{
		return $this->hasMany(\App\Models\EscaleraRound::class)->orderBy('round_number');
	}

	public function isRoundRobin(): bool
	{
		return $this->type === 'round_robin';
	}

	public function roundRobinPlayers()
	{
		return $this->hasMany(RoundRobinPlayer::class);
	}

	public function roundRobinRounds()
	{
		return $this->hasMany(RoundRobinRound::class)->orderBy('round_number');
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

	public function americanoFlexRounds()
	{
		return $this->hasMany(AmericanoFlexRound::class);
	}

	public function americanoFlexPlayers()
	{
		return $this->hasMany(AmericanoFlexPlayer::class);
	}

	public function isAmericanoFlex(): bool
	{
		return $this->type === 'americano_flex';
	}
	/**
     * Одобренные участники
     */
    public function approvedParticipants()
    {
        return $this->belongsToMany(User::class, 'tournament_participants')
                    ->withPivot('status', 'attended_at')
                    ->withTimestamps()
                    ->wherePivot('status', 'registered');
    }

    /**
     * Заявки на модерации
     */
    public function pendingParticipants()
    {
        return $this->belongsToMany(User::class, 'tournament_participants')
                    ->withPivot('status', 'moderation_deadline')
                    ->withTimestamps()
                    ->wherePivot('status', 'pending');
    }

    /**
     * Общее количество участников (registered + pending) с учётом типа турнира
     */
    public function totalParticipantsCount(): int
    {
        if (!$this->usesSoloRegistration()) {
            return $this->teams()->whereIn('status', ['approved', 'pending'])->count() * 2;
        }
        return $this->participants()->count();
    }

    /**
     * Количество одобренных
     */
    public function approvedParticipantsCount(): int
    {
        if (!$this->usesSoloRegistration()) {
            return $this->teams()->where('status', 'approved')->count() * 2;
        }
        return $this->participants()->wherePivot('status', 'registered')->count();
    }

    /**
     * Количество на модерации
     */
    public function pendingParticipantsCount(): int
    {
        if (!$this->usesSoloRegistration()) {
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
            self::PLAYOFF_FORMAT_TOP_BOTTOM => 'Верх/низ (A1+B3 vs A2+B4)',
            self::PLAYOFF_FORMAT_TABLE_QF => 'Общая таблица (1+4 и 2+3 ждут в полуфинале, 5–12 играют четвертьфинал)',
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
	 * Привести названия кортов к их количеству.
	 *
	 * Клиенты присылают courts_count отдельно от названий (мобильное
	 * приложение названия вообще не шлёт), поэтому массив легко расходится
	 * со счётчиком: уменьшили корты — остались лишние названия, увеличили —
	 * не хватает. Приводим здесь, после каждого сохранения турнира.
	 */
	public function syncCourtNames(): void
	{
		$count = (int) $this->courts_count;
		if ($count <= 0) {
			return; // количество не задано — названия не трогаем
		}

		$courts = is_array($this->courts) ? $this->courts : [];
		// Лишние отбрасываем, недостающие добиваем пустыми — подпись
		// таких кортов сгенерирует getCourtName().
		$courts = array_slice($courts, 0, $count);
		$courts = array_pad($courts, $count, null);
		$courts = array_map(fn ($name) => $name !== null && $name !== '' ? $name : null, $courts);

		$this->update([
			'courts' => empty(array_filter($courts)) ? null : $courts,
		]);
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

	/**
	 * Брони кортов, закреплённые за турниром.
	 */
	public function courtBookings()
	{
		return $this->hasMany(\App\Models\CourtBooking::class);
	}

	/**
	 * Был ли уже сыгран первый раунд турнира.
	 */
	public function firstRoundCompleted(): bool
	{
		return match ($this->type) {
			'americano' => \App\Models\AmericanoRound::query()
				->whereIn('tournament_group_id', $this->groups()->pluck('id'))
				->where('round_number', 1)->where('status', 'completed')->exists(),
			'mexicano' => $this->mexicanoRounds()
				->where('round_number', 1)->where('status', 'completed')->exists(),
			'king_of_court' => $this->kingOfCourtRounds()
				->where('round_number', 1)->where('status', 'completed')->exists(),
			'just_padel_it' => \App\Models\JustPadelItRound::query()
				->where('tournament_id', $this->id)
				->where('round_number', 1)->where('status', 'completed')->exists(),
			'bali_koc' => $this->baliKocRounds()
				->where('round_number', 1)->where('status', 'completed')->exists(),
			'americano_flex' => $this->americanoFlexRounds()
				->where('round_number', 1)->where('status', 'completed')->exists(),
			'escalera' => $this->escaleraRounds()
				->where('round_number', 1)->where('status', 'completed')->exists(),
			'team' => \App\Models\TournamentGroupMatch::query()
				->whereIn('group_id', $this->teamGroups()->pluck('id'))
				->where('status', 'completed')->exists(),
			default => $this->playoffMatches()->where('status', 'completed')->exists(),
		};
	}

	/**
	 * Можно ли перезапустить турнир.
	 * Разрешено только пока турнир in_progress и первый раунд ещё не завершён.
	 */
	public function canRestart(): bool
	{
		return $this->status === 'in_progress' && ! $this->firstRoundCompleted();
	}
}