<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Challenge extends Model
{
    use HasFactory;

    const STATUS_OPEN = 'open';
    const STATUS_READY = 'ready';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    const TYPE_RATED = 'rated';
    const TYPE_FRIENDLY = 'friendly';

    protected $fillable = [
        'creator_id',
        'club_id',
        'scheduled_at',
        'type',
        'min_level',
        'max_level',
        'status',
        'score',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'min_level' => 'decimal:2',
        'max_level' => 'decimal:2',
        'score' => 'array',
    ];

    // Relationships

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function players()
    {
        return $this->hasMany(ChallengePlayer::class);
    }

    public function confirmedPlayers()
    {
        return $this->hasMany(ChallengePlayer::class)->where('status', 'confirmed');
    }

    public function teamA()
    {
        return $this->hasMany(ChallengePlayer::class)->whereIn('position', [1, 2])->where('status', 'confirmed');
    }

    public function teamB()
    {
        return $this->hasMany(ChallengePlayer::class)->whereIn('position', [3, 4])->where('status', 'confirmed');
    }

    // Helpers

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isRated(): bool
    {
        return $this->type === self::TYPE_RATED;
    }

    public function confirmedCount(): int
    {
        return $this->confirmedPlayers()->count();
    }

    public function isFull(): bool
    {
        return $this->confirmedCount() >= 4;
    }

    public function hasPlayer(int $userId): bool
    {
        return $this->players()->where('user_id', $userId)->exists();
    }

    public function getAvailablePositions(): array
    {
        // Позиция занята если на ней confirmed или invited игрок.
        // Declined — позиция освобождается.
        $taken = $this->players()
            ->whereIn('status', [
                ChallengePlayer::STATUS_CONFIRMED,
                ChallengePlayer::STATUS_INVITED,
            ])
            ->pluck('position')
            ->toArray();
        return array_values(array_diff([1, 2, 3, 4], $taken));
    }

    public function getStatusNameAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Открыт',
            self::STATUS_READY => 'Готов к игре',
            self::STATUS_IN_PROGRESS => 'В процессе',
            self::STATUS_PENDING_CONFIRMATION => 'Подтверждение счёта',
            self::STATUS_COMPLETED => 'Завершён',
            self::STATUS_CANCELLED => 'Отменён',
            default => $this->status ?? 'Открыт',
        };
    }

    public function getTypeNameAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_RATED => 'Рейтинговый',
            self::TYPE_FRIENDLY => 'Товарищеский',
            default => $this->type,
        };
    }
}
