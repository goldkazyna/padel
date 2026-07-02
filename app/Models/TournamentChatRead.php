<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentChatRead extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'user_id',
        'last_read_message_id',
    ];

    protected $casts = [
        'last_read_message_id' => 'integer',
    ];
}
