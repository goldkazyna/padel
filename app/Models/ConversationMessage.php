<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Сообщение в личной переписке. */
class ConversationMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['conversation_id', 'user_id', 'text', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
