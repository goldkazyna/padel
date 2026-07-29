<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameTransfer extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['game_id', 'from_user_id', 'to_user_id', 'status'];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser()
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
