<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicketAttachment extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'support_ticket_message_id',
        'path',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function message()
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }

    /** Полный URL вложения (webp в public-диске). */
    public function getUrlAttribute(): string
    {
        return url('/storage/' . ltrim($this->path, '/'));
    }
}
