<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmericanoFlexRound extends Model
{
    use HasFactory;

    protected $fillable = ['tournament_id', 'round_number', 'status'];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function matches()
    {
        return $this->hasMany(AmericanoFlexMatch::class);
    }

    public function byes()
    {
        return $this->hasMany(AmericanoFlexBye::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
