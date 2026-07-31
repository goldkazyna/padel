<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Certificate extends Model
{
    const TYPE_NAMED = 'named';
    const TYPE_GENERIC = 'generic';

    protected $fillable = [
        'club_id', 'type', 'recipient_name', 'number', 'title', 'created_by',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Уникальный номер сертификата: CERT-{clubId}-{год}-{6 символов}. */
    public static function generateNumber(int $clubId): string
    {
        do {
            $number = 'CERT-' . $clubId . '-' . now()->format('Y') . '-' . strtoupper(Str::random(6));
        } while (self::where('number', $number)->exists());

        return $number;
    }

    public function getTypeNameAttribute(): string
    {
        return $this->type === self::TYPE_NAMED ? 'Именной' : 'Обычный';
    }

    public function isNamed(): bool
    {
        return $this->type === self::TYPE_NAMED;
    }
}
