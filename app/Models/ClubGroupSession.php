<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroupSession extends Model
{
    protected $fillable = [
        'group_id', 'court_id', 'court_booking_id', 'date', 'start_time', 'end_time',
        'coach_id', 'status', 'held_at', 'conducted_by',
    ];

    protected $casts = [
        'date' => 'date',
        'held_at' => 'datetime',
    ];

    public function group() { return $this->belongsTo(ClubGroup::class, 'group_id'); }
    public function court() { return $this->belongsTo(Court::class, 'court_id'); }
    public function coach() { return $this->belongsTo(User::class, 'coach_id'); }
    public function courtBooking() { return $this->belongsTo(CourtBooking::class, 'court_booking_id'); }
    public function attendance() { return $this->hasMany(ClubGroupAttendance::class, 'session_id'); }
}
