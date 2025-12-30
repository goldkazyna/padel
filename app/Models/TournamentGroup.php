<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentGroup extends Model
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

	public function players()
	{
		return $this->belongsToMany(User::class, 'tournament_group_players')
					->withPivot('total_points', 'rating_before', 'rating_after')
					->withTimestamps()
					->orderByPivot('total_points', 'desc');
	}

    public function rounds()
    {
        return $this->hasMany(AmericanoRound::class)->orderBy('round_number');
    }
}