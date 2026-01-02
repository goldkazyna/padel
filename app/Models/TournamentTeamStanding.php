<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentTeamStanding extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'team_id',
        'played',
        'won',
        'lost',
        'points_for',
        'points_against',
        'points',
    ];

    public function group()
    {
        return $this->belongsTo(TournamentTeamGroup::class, 'group_id');
    }

    public function team()
    {
        return $this->belongsTo(TournamentTeam::class, 'team_id');
    }

    public function getPointsDiffAttribute()
    {
        return $this->points_for - $this->points_against;
    }
}