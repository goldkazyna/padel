<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Короткий матч «Эскалера»: три на корт за раунд, каждый — своя пара
 * из четвёрки игроков корта (каждый с каждым в паре).
 */
class EscaleraMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'escalera_round_court_id',
        'match_number',
        'team1_player1_id',
        'team1_player2_id',
        'team2_player1_id',
        'team2_player2_id',
        'team1_score',
        'team2_score',
        'status',
    ];

    public function court()
    {
        return $this->belongsTo(EscaleraRoundCourt::class, 'escalera_round_court_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
