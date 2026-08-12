<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Счёт клиенту: платёжная ссылка Plexy на произвольную сумму.
 *
 * Не привязан к объектам CRM — назначение платежа живёт в описании.
 * Оплата приходит вебхуком по `merchantReference` вида «paylink-{id}».
 */
class PaymentLink extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'club_id',
        'created_by',
        'club_client_id',
        'amount',
        'description',
        'client_name',
        'client_phone',
        'status',
        'plexy_link_id',
        'plexy_url',
        'expires_at',
        'paid_at',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function client()
    {
        return $this->belongsTo(ClubClient::class, 'club_client_id');
    }

    /** Ссылка для Plexy: по ней вебхук находит счёт. */
    public function orderReference(): string
    {
        return 'paylink-' . $this->id;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Ждём оплату: не оплачен, не отменён и срок ещё не вышел. */
    public function isAwaitingPayment(): bool
    {
        return $this->status === self::STATUS_PENDING
            && (!$this->expires_at || $this->expires_at->isFuture());
    }

    /** Срок вышел, а оплаты не было. */
    public function isStale(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->expires_at
            && $this->expires_at->isPast();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'Оплачен',
            self::STATUS_CANCELLED => 'Отменён',
            self::STATUS_EXPIRED => 'Просрочен',
            default => $this->isStale() ? 'Просрочен' : 'Ждёт оплаты',
        };
    }

    public function scopeForClub($query, int $clubId)
    {
        return $query->where('club_id', $clubId);
    }
}
