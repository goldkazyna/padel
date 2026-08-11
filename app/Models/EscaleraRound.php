<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Раунд турнира «Ladder»: набор кортов, на каждом из которых играется
 * своя четвёрка игроков.
 */
class EscaleraRound extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'round_number',
        'status',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function courts()
    {
        return $this->hasMany(EscaleraRoundCourt::class)->orderBy('court_number');
    }

    public function results()
    {
        return $this->hasMany(EscaleraRoundResult::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
