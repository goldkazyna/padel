<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RatingHistory extends Model
{
    use HasFactory;

    protected $table = 'rating_history';

    protected $fillable = [
        'user_id',
        'tournament_id',
        'rating_before',
        'rating_after',
        'change',
        'reason',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }
}