<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subject',
        'status',
        'is_urgent',
        'category',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'is_urgent' => 'boolean',
    ];

    /** Допустимые категории (метки) тикета. */
    public const CATEGORIES = ['Аккаунт', 'Оплата', 'Турнир', 'Бронь', 'Другое'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('id');
    }

    /** Непрочитанные игроком ответы поддержки. */
    public function unreadSupportMessages()
    {
        return $this->messages()
            ->where('author_type', 'support')
            ->whereNull('read_at');
    }
}
