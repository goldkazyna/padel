<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Корт внутри раунда «Ladder»: четвёрка игроков в порядке посадки —
 * от неё строится очерёдность трёх коротких матчей.
 */
class EscaleraRoundCourt extends Model
{
    use HasFactory;

    protected $fillable = [
        'escalera_round_id',
        'court_number',
        'player1_id',
        'player2_id',
        'player3_id',
        'player4_id',
    ];

    public function round()
    {
        return $this->belongsTo(EscaleraRound::class, 'escalera_round_id');
    }

    public function matches()
    {
        return $this->hasMany(EscaleraMatch::class)->orderBy('match_number');
    }

    public function player1()
    {
        return $this->belongsTo(User::class, 'player1_id');
    }

    public function player2()
    {
        return $this->belongsTo(User::class, 'player2_id');
    }

    public function player3()
    {
        return $this->belongsTo(User::class, 'player3_id');
    }

    public function player4()
    {
        return $this->belongsTo(User::class, 'player4_id');
    }

    /** Четыре id игроков в порядке посадки. */
    public function playerIds(): array
    {
        return [
            $this->player1_id,
            $this->player2_id,
            $this->player3_id,
            $this->player4_id,
        ];
    }
}
