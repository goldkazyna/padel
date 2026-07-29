<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameActionLog extends Model
{
    protected $fillable = ['game_id', 'user_id', 'action', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
