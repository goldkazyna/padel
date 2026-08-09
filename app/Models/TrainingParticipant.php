<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Запись игрока на тренировку.
 *
 * Отметки reminded_* хранятся здесь, а не на тренировке: напоминания уходят
 * персонально, и человек, записавшийся за час до начала, не должен получить
 * суточное напоминание задним числом.
 */
class TrainingParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'training_id',
        'user_id',
        'reminded_1d_at',
        'reminded_2h_at',
        'reminded_1h_at',
    ];

    protected $casts = [
        'reminded_1d_at' => 'datetime',
        'reminded_2h_at' => 'datetime',
        'reminded_1h_at' => 'datetime',
    ];

    public function training()
    {
        return $this->belongsTo(Training::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
