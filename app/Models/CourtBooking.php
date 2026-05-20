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
        'discount',
        'is_processed',
        'comment',
        'booking_type',
        'coach_id',
        'needs_coach',
    ];

    protected $casts = [
        'date' => 'date',
        'cancelled_at' => 'datetime',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'is_paid' => 'boolean',
        'is_processed' => 'boolean',
        'needs_coach' => 'boolean',
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
}
