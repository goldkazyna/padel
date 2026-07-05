<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JustPadelItMatch extends Model
{
    use HasFactory;

    protected $table = 'just_padel_it_matches';

    protected $fillable = [
        'just_padel_it_round_id',
        'court_number',
        'team1_player1_id',
        'team1_player2_id',
        'team2_player1_id',
        'team2_player2_id',
        'team1_score',
        'team2_score',
        'status',
    ];

    public function round()
    {
        return $this->belongsTo(JustPadelItRound::class, 'just_padel_it_round_id');
    }

    public function team1Player1()
    {
        return $this->belongsTo(User::class, 'team1_player1_id');
    }

    public function team1Player2()
    {
        return $this->belongsTo(User::class, 'team1_player2_id');
    }

    public function team2Player1()
    {
        return $this->belongsTo(User::class, 'team2_player1_id');
    }

    public function team2Player2()
    {
        return $this->belongsTo(User::class, 'team2_player2_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getPlayersAttribute(): array
    {
        return [
            $this->team1_player1_id,
            $this->team1_player2_id,
            $this->team2_player1_id,
            $this->team2_player2_id,
        ];
    }

    public function getWinningTeamAttribute(): ?int
    {
        if (!$this->isCompleted()) return null;
        if ($this->team1_score > $this->team2_score) return 1;
        if ($this->team2_score > $this->team1_score) return 2;
        return null;
    }
}
