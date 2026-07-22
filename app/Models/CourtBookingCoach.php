<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Дополнительный тренер на индивидуальной броне (спарринг с несколькими тренерами).
 * У каждого своя цена и статус оплаты.
 */
class CourtBookingCoach extends Model
{
    protected $fillable = ['court_booking_id', 'coach_id', 'coach_price', 'coach_paid'];

    protected $casts = [
        'coach_price' => 'decimal:2',
        'coach_paid' => 'boolean',
    ];

    public function booking()
    {
        return $this->belongsTo(CourtBooking::class, 'court_booking_id');
    }

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }
}
