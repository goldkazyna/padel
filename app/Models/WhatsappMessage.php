<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Сообщение WhatsApp, полученное вебхуком Whapi.Cloud.
 */
class WhatsappMessage extends Model
{
    protected $fillable = [
        'club_id', 'wa_message_id', 'channel_id', 'chat_id', 'phone',
        'author_name', 'from_me', 'type', 'body', 'payload', 'sent_at',
    ];

    protected $casts = [
        'from_me' => 'boolean',
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * Карточка клиента с этим номером, если она заведена.
     *
     * Номер в WhatsApp приходит цифрами, в карточке клиента записан как
     * придётся — сравниваем по последним десяти цифрам, как везде в CRM.
     */
    public function client(): ?ClubClient
    {
        $tail = substr(preg_replace('/\D/', '', (string) $this->phone), -10);
        if (strlen($tail) !== 10 || !$this->club_id) {
            return null;
        }

        $normalized = "REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '(', ''), ')', '')";

        return ClubClient::where('club_id', $this->club_id)
            ->whereRaw("REPLACE({$normalized}, '-', '') LIKE ?", ['%' . $tail])
            ->first();
    }

    /** Короткая подпись типа для ленты: у нетекстовых сообщений тела нет. */
    public function preview(): string
    {
        if ($this->body !== null && trim($this->body) !== '') {
            return $this->body;
        }

        return match ($this->type) {
            'image' => '📷 фото',
            'video' => '🎬 видео',
            'voice', 'audio' => '🎤 голосовое',
            'document' => '📄 документ',
            'sticker' => 'стикер',
            'location' => 'геометка',
            'contact', 'contacts' => 'контакт',
            // Служебные события WhatsApp (добавили в группу, удалили
            // сообщение) в списке выглядели как текст «action».
            'action' => 'служебное событие',
            'system' => 'системное сообщение',
            'notification' => 'уведомление WhatsApp',
            default => $this->type,
        };
    }
}
