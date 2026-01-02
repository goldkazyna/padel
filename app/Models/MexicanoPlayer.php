<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MexicanoPlayer extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'user_id',
        'total_points',
        'rating_before',
        'rating_after',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}