<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmericanoFlexMatch extends Model
{
    protected $fillable = [
        'americano_flex_round_id', 'court_number',
        'team1_player1_id', 'team1_player2_id', 'team2_player1_id', 'team2_player2_id',
        'team1_score', 'team2_score', 'status',
    ];

    public function round()
    {
        return $this->belongsTo(AmericanoFlexRound::class, 'americano_flex_round_id');
    }

    public function team1Player1() { return $this->belongsTo(User::class, 'team1_player1_id'); }
    public function team1Player2() { return $this->belongsTo(User::class, 'team1_player2_id'); }
    public function team2Player1() { return $this->belongsTo(User::class, 'team2_player1_id'); }
    public function team2Player2() { return $this->belongsTo(User::class, 'team2_player2_id'); }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
