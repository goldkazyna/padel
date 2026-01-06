<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentPlayoffMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'stage',
        'match_number',
        'team1_id',
        'team2_id',
        'team1_source',
        'team2_source',
        'team1_score',
        'team2_score',
        'winner_id',
        'status',
		'team1_player1_id',
		'team1_player2_id',
		'team2_player1_id',
		'team2_player2_id',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team1()
    {
        return $this->belongsTo(TournamentTeam::class, 'team1_id');
    }

    public function team2()
    {
        return $this->belongsTo(TournamentTeam::class, 'team2_id');
    }

    public function winner()
    {
        return $this->belongsTo(TournamentTeam::class, 'winner_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getStageNameAttribute(): string
    {
        return match($this->stage) {
            'final' => 'Финал',
            'semi' => 'Полуфинал',
            'quarter' => '1/4 финала',
            'eighth' => '1/8 финала',
            default => $this->stage,
        };
    }
	// Игроки команды 1 (для Американо)
	public function team1Player1()
	{
		return $this->belongsTo(User::class, 'team1_player1_id');
	}

	public function team1Player2()
	{
		return $this->belongsTo(User::class, 'team1_player2_id');
	}

	// Игроки команды 2 (для Американо)
	public function team2Player1()
	{
		return $this->belongsTo(User::class, 'team2_player1_id');
	}

	public function team2Player2()
	{
		return $this->belongsTo(User::class, 'team2_player2_id');
	}

	/**
	 * Это матч Американо (по игрокам, не по командам)
	 */
	public function isAmericanoMatch(): bool
	{
		return $this->team1_player1_id !== null;
	}
}