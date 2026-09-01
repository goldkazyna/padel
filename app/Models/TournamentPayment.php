<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Оплата участия в турнире.
 *
 * Пока платёж висит в pending и не протух, он держит место: иначе человек
 * уходит платить, за это время место занимают, и после оплаты его некуда
 * посадить. После оплаты игрок попадает в основной список без модерации.
 */
class TournamentPayment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /** Сколько минут держим место, пока человек платит. */
    public const HOLD_MINUTES = 20;

    protected $fillable = [
        'tournament_id', 'user_id', 'friend_user_id', 'players_count',
        'amount', 'status', 'plexy_link_id', 'plexy_url', 'expires_at', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'players_count' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function friend()
    {
        return $this->belongsTo(User::class, 'friend_user_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Ещё ждём оплату и место держим. */
    public function isHolding(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /** Ссылка на оплату в Plexy — «tourpay-12». */
    public function orderReference(): string
    {
        return 'tourpay-' . $this->id;
    }

    /** Незавершённые платежи, которые прямо сейчас держат места. */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('expires_at', '>', now());
    }

    /** Сколько мест держат неоплаченные, но живые платежи турнира. */
    public static function heldSlots(int $tournamentId): int
    {
        return (int) static::where('tournament_id', $tournamentId)
            ->holding()
            ->sum('players_count');
    }
}
