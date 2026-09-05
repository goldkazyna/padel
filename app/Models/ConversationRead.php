<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** До какого сообщения участник дочитал диалог. */
class ConversationRead extends Model
{
    protected $fillable = ['conversation_id', 'user_id', 'last_read_message_id'];

    protected $casts = ['last_read_message_id' => 'integer'];
}
