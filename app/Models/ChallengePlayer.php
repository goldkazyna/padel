<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChallengePlayer extends Model
{
    use HasFactory;

    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_INVITED = 'invited';
    const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'challenge_id',
        'user_id',
        'position',
        'status',
        'rating_before',
        'rating_after',
        'rating_change',
        'score_confirmed',
    ];

    protected $casts = [
        'position' => 'integer',
        'rating_before' => 'integer',
        'rating_after' => 'integer',
        'rating_change' => 'integer',
        'score_confirmed' => 'boolean',
    ];

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isInvited(): bool
    {
        return $this->status === self::STATUS_INVITED;
    }

    public function isTeamA(): bool
    {
        return in_array($this->position, [1, 2]);
    }

    public function isTeamB(): bool
    {
        return in_array($this->position, [3, 4]);
    }
}
