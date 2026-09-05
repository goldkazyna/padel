<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameActionLog extends Model
{
    const ACTION_START = 'start';
    const ACTION_START_CANCEL = 'start_cancel';
    const ACTION_FINISH = 'finish';
    const ACTION_ROUND_ADD = 'round_add';
    const ACTION_ROUND_UPDATE = 'round_update';
    const ACTION_ROUND_DELETE = 'round_delete';
    const ACTION_PLAYER_REMOVE = 'player_remove';
    const ACTION_SCHEDULE_REGENERATE = 'schedule_regenerate';
    const ACTION_CANCEL = 'cancel';
    /** Пришёл из ленты и сразу сел в состав. */
    const ACTION_JOIN = 'join';
    /** Пришёл, но мест не было — встал в очередь. */
    const ACTION_APPLY = 'apply';

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
