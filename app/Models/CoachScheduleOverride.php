<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachScheduleOverride extends Model
{
    protected $fillable = [
        'club_coach_id',
        'date',
        'start_time',
        'end_time',
        'is_available',
        'reason',
    ];

    protected $casts = [
        'date' => 'date',
        'is_available' => 'boolean',
    ];

    public function clubCoach()
    {
        return $this->belongsTo(ClubCoach::class);
    }
}
