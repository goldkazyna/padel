<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentTeamGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'name',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function standings()
    {
        return $this->hasMany(TournamentTeamStanding::class, 'group_id')
                    ->orderBy('points', 'desc')
                    ->orderByRaw('points_for - points_against DESC')
                    ->orderBy('won', 'desc');
    }

    public function matches()
    {
        return $this->hasMany(TournamentGroupMatch::class, 'group_id');
    }

    public function teams()
    {
        return $this->belongsToMany(TournamentTeam::class, 'tournament_team_standings', 'group_id', 'team_id');
    }

    public function isCompleted(): bool
    {
        return $this->matches()->where('status', '!=', 'completed')->count() === 0;
    }
}