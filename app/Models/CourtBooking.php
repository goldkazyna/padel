<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtBooking extends Model
{
    protected $fillable = [
        'court_id',
        'date',
        'start_time',
        'end_time',
        'client_name',
        'client_phone',
        'status',
        'cancelled_at',
        'booked_by',
        'price',
        'payment_method',
        'is_paid',
        'payment_status',
        'payment_id',
        'paid_at',
        'discount',
        'is_processed',
        'comment',
        'booking_type',
        'tournament_id',
        'source',
        'coach_id',
        'coach_paid',
        'coach_price',
        'needs_coach',
        'club_card_id',
        'certificate_id',
        'cancel_reason',
        'card_charged_at',
        'reminded_1d_at',
        'reminded_2h_at',
        'reminded_1h_at',
    ];

    protected $casts = [
        'date' => 'date',
        'cancelled_at' => 'datetime',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'is_paid' => 'boolean',
        'paid_at' => 'datetime',
        'is_processed' => 'boolean',
        'needs_coach' => 'boolean',
        'coach_paid' => 'boolean',
        'coach_price' => 'decimal:2',
        'card_charged_at' => 'datetime',
        'reminded_1d_at' => 'datetime',
        'reminded_2h_at' => 'datetime',
        'reminded_1h_at' => 'datetime',
    ];

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * Турнир, за которым закреплена бронь (для booking_type = 'tournament').
     */
    public function tournament()
    {
        return $this->belongsTo(\App\Models\Tournament::class);
    }

    public function bookedByUser()
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    /** Все тренеры брони (мультитренер / спарринг) — у каждого своя цена и оплата. */
    public function coaches()
    {
        return $this->hasMany(CourtBookingCoach::class, 'court_booking_id');
    }

    /** Позиции инвентаря, выданные по этой броне. */
    public function inventoryItems()
    {
        return $this->hasMany(CourtBookingInventoryItem::class, 'court_booking_id');
    }

    /** Сумма за инвентарь по броне (в цену корта не входит). */
    public function inventoryTotal(): int
    {
        return (int) $this->inventoryItems->sum(fn ($row) => $row->total);
    }

    /**
     * Брони, где данный пользователь — тренер: либо основной (coach_id),
     * либо один из нескольких (пивот court_booking_coaches).
     * Мультитренерская бронь должна попадать в расписание КАЖДОГО тренера.
     */
    public function scopeForCoach($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('coach_id', $userId)
                ->orWhereHas('coaches', fn ($c) => $c->where('coach_id', $userId));
        });
    }

    public function clubCard()
    {
        return $this->belongsTo(ClubCard::class, 'club_card_id');
    }

    /**
     * При отмене брони, к которой привязано групповое занятие, отменяем и его.
     * Связь хранится в club_group_sessions.court_booking_id.
     */
    protected static function booted(): void
    {
        static::updated(function (CourtBooking $b) {
            if (!$b->wasChanged('status')) return;
            if ($b->status !== 'cancelled') return;
            $session = \App\Models\ClubGroupSession::where('court_booking_id', $b->id)
                ->where('status', '!=', 'cancelled')
                ->first();
            if ($session) {
                $session->update(['status' => 'cancelled']);
                // Сюда попадаем, только если сессию не отменили явно из потока группы
                // (там её статус ставят заранее). Значит бронь сняли в расписании —
                // фиксируем это в журнале группы с понятной причиной.
                $group = \App\Models\ClubGroup::find($session->group_id);
                $dateLabel = $session->date->format('d.m.Y') . ' ' . \Carbon\Carbon::parse($session->start_time)->format('H:i');
                \App\Models\ActivityLog::logGroup(
                    $session->group_id,
                    'cancelled',
                    'ClubGroupSession',
                    $session->id,
                    'Занятие отменено — бронь корта снята в расписании' . ($group ? ": «{$group->name}» ({$dateLabel})" : ''),
                    clubId: $group?->club_id,
                );
            }
        });
    }
}
