<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachSchedule extends Model
{
    protected $fillable = [
        'club_coach_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    public function clubCoach()
    {
        return $this->belongsTo(ClubCoach::class);
    }
}
