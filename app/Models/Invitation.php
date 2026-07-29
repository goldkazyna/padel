<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    const KIND_GAME = 'game';
    const KIND_TOURNAMENT = 'tournament';
    const KIND_TRAINING = 'training';

    protected $fillable = [
        'user_id', 'inviter_id', 'invitable_type', 'invitable_id', 'kind', 'status', 'expires_at',
    ];

    protected $casts = ['expires_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function invitable()
    {
        return $this->morphTo();
    }
}
