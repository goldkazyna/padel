<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameMatch extends Model
{
    use HasFactory;

    protected $table = 'matches';

    protected $fillable = [
        'tournament_id',
        'player1_id',
        'player2_id',
        'winner_id',
        'score',
        'player1_rating_before',
        'player2_rating_before',
        'player1_rating_after',
        'player2_rating_after',
        'rating_change',
        'status',
    ];

    // Связи
    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function player1()
    {
        return $this->belongsTo(User::class, 'player1_id');
    }

    public function player2()
    {
        return $this->belongsTo(User::class, 'player2_id');
    }

    public function winner()
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function loser()
    {
        if (!$this->winner_id) return null;
        
        return $this->winner_id === $this->player1_id ? $this->player2 : $this->player1;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getRatingChangeFor(User $user): ?int
    {
        if (!$this->isCompleted()) return null;

        if ($user->id === $this->player1_id) {
            return $this->player1_rating_after - $this->player1_rating_before;
        }
        
        if ($user->id === $this->player2_id) {
            return $this->player2_rating_after - $this->player2_rating_before;
        }

        return null;
    }
}