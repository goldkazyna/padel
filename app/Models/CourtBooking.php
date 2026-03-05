<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class CourtBooking extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_slot_id',
        'user_id',
        'status',
        'payment_status',
        'cancelled_at',
        'cancel_reason',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
    ];

    public function courtSlot()
    {
        return $this->belongsTo(CourtSlot::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function canCancel(): bool
    {
        if ($this->status === 'cancelled') {
            return false;
        }

        $slot = $this->courtSlot;
        $club = $slot->court->club;
        $cancelHours = $club->booking_cancel_hours;

        $slotStart = Carbon::parse($slot->date->format('Y-m-d') . ' ' . $slot->start_time);

        return now()->diffInHours($slotStart, false) >= $cancelHours;
    }
}
