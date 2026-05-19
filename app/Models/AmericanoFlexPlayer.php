<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmericanoFlexPlayer extends Model
{
    protected $fillable = [
        'tournament_id', 'user_id',
        'total_points', 'matches_played', 'bye_count', 'bye_streak',
        'rating_before', 'rating_after',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Среднее очков на матч (для лидерборда). */
    public function getAverageScoreAttribute(): float
    {
        return $this->matches_played > 0
            ? round($this->total_points / $this->matches_played, 2)
            : 0.0;
    }
}
