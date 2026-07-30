<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    use HasFactory;

    const STATUS_OPEN = 'open';
    const STATUS_FULL = 'full';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_FINISHED = 'finished';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_DISPUTED = 'disputed';

    const TYPE_RATED = 'rated';
    const TYPE_FRIENDLY = 'friendly';

    const VISIBILITY_PUBLIC = 'public';
    const VISIBILITY_PRIVATE = 'private';

    const FORMAT_SETS = 'sets';
    const FORMAT_POINTS = 'points';
    const FORMAT_AMERICANO = 'americano';

    protected $fillable = [
        'creator_id', 'club_id', 'court_id', 'starts_at', 'ends_at',
        'type', 'visibility', 'format', 'format_meta', 'rating_min', 'rating_max',
        'capacity', 'price', 'description', 'status', 'score_locked',
        'share_token', 'share_expires_at', 'share_max_uses', 'share_uses', 'share_revoked_at',
        'reminded_1d_at', 'reminded_2h_at', 'reminded_1h_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'format_meta' => 'array',
        'rating_min' => 'decimal:2',
        'rating_max' => 'decimal:2',
        'capacity' => 'integer',
        'price' => 'integer',
        'score_locked' => 'boolean',
        'share_expires_at' => 'datetime',
        'share_max_uses' => 'integer',
        'share_uses' => 'integer',
        'share_revoked_at' => 'datetime',
        'reminded_1d_at' => 'datetime',
        'reminded_2h_at' => 'datetime',
        'reminded_1h_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function players()
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function acceptedPlayers()
    {
        return $this->hasMany(GamePlayer::class)->where('status', GamePlayer::STATUS_ACCEPTED);
    }

    public function rounds()
    {
        return $this->hasMany(GameRound::class)->orderBy('round_no');
    }

    public function transfers()
    {
        return $this->hasMany(GameTransfer::class);
    }

    public function invitations()
    {
        return $this->morphMany(Invitation::class, 'invitable');
    }

    public function isOrganizer(int $userId): bool
    {
        return $this->creator_id === $userId;
    }

    public function acceptedCount(): int
    {
        return $this->players()->where('status', GamePlayer::STATUS_ACCEPTED)->count();
    }

    public function isFull(): bool
    {
        return $this->acceptedCount() >= (int) $this->capacity;
    }

    public function getAvailablePositions(): array
    {
        // Позиция занята, если на ней accepted или invited игрок.
        $taken = $this->players()
            ->whereIn('status', [GamePlayer::STATUS_ACCEPTED, GamePlayer::STATUS_INVITED])
            ->pluck('position')
            ->filter()
            ->all();
        $all = range(1, (int) $this->capacity);
        return array_values(array_diff($all, $taken));
    }

    public function shareLinkActive(): bool
    {
        if (!$this->share_token || $this->share_revoked_at) {
            return false;
        }
        if ($this->share_expires_at && $this->share_expires_at->isPast()) {
            return false;
        }
        if ($this->share_max_uses !== null && $this->share_uses >= $this->share_max_uses) {
            return false;
        }
        return true;
    }

    public function getStatusNameAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Открыта',
            self::STATUS_FULL => 'Состав собран',
            self::STATUS_IN_PROGRESS => 'В процессе',
            self::STATUS_FINISHED => 'Завершена',
            self::STATUS_CANCELLED => 'Отменена',
            self::STATUS_DISPUTED => 'Оспаривается',
            default => $this->status ?? 'Открыта',
        };
    }

    public function getTypeNameAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_RATED => 'Рейтинговая',
            self::TYPE_FRIENDLY => 'Товарищеская',
            default => $this->type,
        };
    }

    public function getFormatNameAttribute(): string
    {
        return match ($this->format) {
            self::FORMAT_SETS => 'По сетам',
            self::FORMAT_POINTS => 'До N очков',
            self::FORMAT_AMERICANO => 'Американо',
            default => $this->format,
        };
    }
}
