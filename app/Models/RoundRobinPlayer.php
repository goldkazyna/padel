<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoundRobinPlayer extends Model
{
    protected $table = 'round_robin_players';

    protected $fillable = [
        'tournament_id',
        'user_id',
        'position',
        'wins',
        'losses',
        'points_for',
        'points_against',
        'rating_before',
        'rating_after',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
