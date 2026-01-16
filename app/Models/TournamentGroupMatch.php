<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentGroupMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
		'court_number',
        'team1_id',
        'team2_id',
        'team1_score',
        'team2_score',
        'round_number',
        'status',
    ];

    public function group()
    {
        return $this->belongsTo(TournamentTeamGroup::class, 'group_id');
    }

    public function team1()
    {
        return $this->belongsTo(TournamentTeam::class, 'team1_id');
    }

    public function team2()
    {
        return $this->belongsTo(TournamentTeam::class, 'team2_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getWinnerAttribute()
    {
        if (!$this->isCompleted()) return null;
        if ($this->team1_score > $this->team2_score) return $this->team1;
        if ($this->team2_score > $this->team1_score) return $this->team2;
        return null;
    }

    public function getWinnerIdAttribute()
    {
        if (!$this->isCompleted()) return null;
        if ($this->team1_score > $this->team2_score) return $this->team1_id;
        if ($this->team2_score > $this->team1_score) return $this->team2_id;
        return null;
    }
}