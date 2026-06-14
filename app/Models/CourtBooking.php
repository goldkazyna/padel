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
        'coach_id',
        'coach_paid',
        'coach_price',
        'needs_coach',
        'club_card_id',
        'card_charged_at',
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
    ];

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function bookedByUser()
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
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
            }
        });
    }
}
