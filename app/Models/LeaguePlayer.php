<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Участник лиги. Записываются один раз в лигу, а не в каждый этап.
 */
class LeaguePlayer extends Model
{
    protected $fillable = ['league_id', 'user_id', 'status', 'joined_at', 'left_at'];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function league()
    {
        return $this->belongsTo(League::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
