<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentChatMessage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'tournament_id',
        'user_id',
        'text',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
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
