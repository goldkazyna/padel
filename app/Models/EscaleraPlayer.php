<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Участник турнира «Ladder»: стартовый и текущий корт, накопленные
 * очки/победы, рейтинг до и после турнира.
 */
class EscaleraPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'user_id',
        'total_points',
        'total_raw_points',
        'start_court',
        'current_court',
        'wins',
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
