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
}