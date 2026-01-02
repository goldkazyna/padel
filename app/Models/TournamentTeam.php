<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'player1_id',
        'player2_id',
        'name',
        'seed',
        'rating_avg',
        'rating_before',
        'rating_after',
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

    public function standings()
    {
        return $this->hasMany(TournamentTeamStanding::class, 'team_id');
    }

    public function getNameAttribute($value)
    {
        if ($value) return $value;
        return $this->player1->first_name . ' / ' . $this->player2->first_name;
    }

    public function getFullNameAttribute()
    {
        return $this->player1->full_name . ' / ' . $this->player2->full_name;
    }

    public function getPlayersAttribute()
    {
        return [$this->player1, $this->player2];
    }
}