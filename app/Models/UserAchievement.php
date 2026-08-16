<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Прогресс игрока по одному значку.
 * Определение значка (название, иконка, условие) живёт в app/Achievements/.
 */
class UserAchievement extends Model
{
    protected $fillable = ['user_id', 'code', 'progress', 'target', 'unlocked_at', 'notified_at'];

    protected $casts = [
        'progress' => 'integer',
        'target' => 'integer',
        'unlocked_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isUnlocked(): bool
    {
        return $this->unlocked_at !== null;
    }
}
