<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoundRobinRound extends Model
{
    protected $table = 'round_robin_rounds';

    protected $fillable = [
        'tournament_id',
        'round_number',
        'status',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function matches()
    {
        return $this->hasMany(RoundRobinMatch::class, 'round_robin_round_id')->orderBy('court_number');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }
}
