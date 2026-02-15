<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAuthToken extends Model
{
    public $timestamps = false;

    protected $fillable = ['token', 'user_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeNotExpired($query)
    {
        return $query->where('created_at', '>', now()->subMinutes(5));
    }
}
