<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Личная переписка двух игроков.
 *
 * Пара хранится упорядоченной (user_one_id < user_two_id), поэтому диалог
 * между двумя людьми всегда один — с какой бы стороны его ни открыли.
 */
class Conversation extends Model
{
    protected $fillable = ['user_one_id', 'user_two_id', 'last_message_at'];

    protected $casts = ['last_message_at' => 'datetime'];

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function userOne()
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo()
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function reads()
    {
        return $this->hasMany(ConversationRead::class);
    }

    /** Найти или завести диалог между двумя игроками. */
    public static function between(int $a, int $b): self
    {
        [$one, $two] = $a < $b ? [$a, $b] : [$b, $a];

        return static::firstOrCreate(['user_one_id' => $one, 'user_two_id' => $two]);
    }

    /** Собеседник для указанного участника. */
    public function otherUserId(int $userId): int
    {
        return $this->user_one_id === $userId ? $this->user_two_id : $this->user_one_id;
    }

    public function hasParticipant(int $userId): bool
    {
        return $this->user_one_id === $userId || $this->user_two_id === $userId;
    }
}
