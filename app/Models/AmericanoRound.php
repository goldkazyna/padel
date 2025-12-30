<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmericanoRound extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_group_id',
        'round_number',
        'status',
    ];

    public function group()
    {
        return $this->belongsTo(TournamentGroup::class, 'tournament_group_id');
    }

    public function matches()
    {
        return $this->hasMany(AmericanoMatch::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}