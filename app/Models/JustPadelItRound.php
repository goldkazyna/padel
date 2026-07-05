<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JustPadelItRound extends Model
{
    use HasFactory;

    protected $table = 'just_padel_it_rounds';

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
        return $this->hasMany(JustPadelItMatch::class, 'just_padel_it_round_id')->orderBy('court_number');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }
}
