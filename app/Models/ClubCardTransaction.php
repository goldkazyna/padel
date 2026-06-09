<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubCardTransaction extends Model
{
    protected $fillable = [
        'club_id',
        'club_card_id',
        'court_booking_id',
        'amount',
        'balance_after',
        'note',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    public function card()
    {
        return $this->belongsTo(ClubCard::class, 'club_card_id');
    }

    public function booking()
    {
        return $this->belongsTo(CourtBooking::class, 'court_booking_id');
    }
}
