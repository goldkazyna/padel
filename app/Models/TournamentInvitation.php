<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentInvitation extends Model
{
    protected $fillable = [
        'tournament_id', 'user_id', 'invited_by', 'status', 'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
