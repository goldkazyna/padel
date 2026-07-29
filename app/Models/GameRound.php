<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameRound extends Model
{
    protected $fillable = [
        'game_id', 'round_no', 'pair_a', 'pair_b',
        'score_a', 'score_b', 'tiebreak_a', 'tiebreak_b', 'is_played',
    ];

    protected $casts = [
        'round_no' => 'integer',
        'pair_a' => 'array',
        'pair_b' => 'array',
        'score_a' => 'integer',
        'score_b' => 'integer',
        'tiebreak_a' => 'integer',
        'tiebreak_b' => 'integer',
        'is_played' => 'boolean',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }
}
