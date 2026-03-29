<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachBlock extends Model
{
    protected $fillable = ['club_coach_id', 'date', 'start_time', 'end_time', 'reason'];

    protected $casts = ['date' => 'date'];

    public function clubCoach()
    {
        return $this->belongsTo(ClubCoach::class);
    }
}
