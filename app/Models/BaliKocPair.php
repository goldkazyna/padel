<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaliKocPair extends Model
{
    use HasFactory;

    protected $table = 'bali_koc_pairs';

    protected $fillable = [
        'tournament_id',
        'player1_id',
        'player2_id',
        'points',
        'wins',
        'losses',
        'games_for',
        'games_against',
        'player1_rating_before',
        'player1_rating_after',
        'player2_rating_before',
        'player2_rating_after',
    ];

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

    public function getDisplayNameAttribute(): string
    {
        $n1 = $this->player1->name ?? '?';
        $n2 = $this->player2->name ?? '?';
        return "{$n1} / {$n2}";
    }

    public function getGameDiffAttribute(): int
    {
        return (int) $this->games_for - (int) $this->games_against;
    }
}
