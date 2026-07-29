<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GamePlayer extends Model
{
    use HasFactory;

    const STATUS_INVITED = 'invited';
    const STATUS_CANDIDATE = 'candidate';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';
    const STATUS_LEFT = 'left';
    const STATUS_REMOVED = 'removed';

    const SOURCE_CREATOR = 'creator';
    const SOURCE_INVITE = 'invite';
    const SOURCE_APP_FEED = 'app_feed';
    const SOURCE_APP_LINK = 'app_link';

    protected $fillable = [
        'game_id', 'user_id', 'position', 'status', 'source', 'out_of_range',
        'rating_before', 'rating_after', 'rating_change', 'score_confirmed', 'responded_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'out_of_range' => 'boolean',
        'rating_before' => 'integer',
        'rating_after' => 'integer',
        'rating_change' => 'integer',
        'score_confirmed' => 'boolean',
        'responded_at' => 'datetime',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }
}
