<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JustPadelItPlayer extends Model
{
    use HasFactory;

    protected $table = 'just_padel_it_players';

    protected $fillable = [
        'tournament_id',
        'user_id',
        'total_points',
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
