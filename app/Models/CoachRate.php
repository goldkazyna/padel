<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachRate extends Model
{
    protected $fillable = [
        'club_coach_id',
        'hours',
        'rate',
    ];

    protected $casts = [
        'hours' => 'integer',
        'rate' => 'decimal:2',
    ];

    public function clubCoach()
    {
        return $this->belongsTo(ClubCoach::class);
    }
}
