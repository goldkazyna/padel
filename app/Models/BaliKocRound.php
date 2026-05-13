<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaliKocRound extends Model
{
    use HasFactory;

    protected $table = 'bali_koc_rounds';

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
        return $this->hasMany(BaliKocMatch::class, 'bali_koc_round_id')->orderBy('court_number');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
