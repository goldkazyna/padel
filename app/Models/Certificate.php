<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Certificate extends Model
{
    const TYPE_NAMED = 'named';
    const TYPE_GENERIC = 'generic';

    const VALUE_AMOUNT = 'amount';
    const VALUE_HOURS = 'hours';

    protected $fillable = [
        'club_id', 'type', 'recipient_name', 'client_id', 'number', 'title', 'created_by', 'template_id',
        'value_type', 'amount', 'hours',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function client()
    {
        return $this->belongsTo(ClubClient::class, 'client_id');
    }

    public function template()
    {
        return $this->belongsTo(CertificateTemplate::class, 'template_id');
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

    /** Читаемый номинал: «5 000 ₸» или «2 часа». */
    public function valueLabel(): string
    {
        if ($this->value_type === self::VALUE_HOURS) {
            $h = (int) ($this->hours ?? 0);
            return $h . ' ' . self::pluralHours($h);
        }
        return number_format((int) ($this->amount ?? 0), 0, '', ' ') . ' ₸';
    }

    private static function pluralHours(int $n): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return 'часов';
        if ($n1 > 1 && $n1 < 5) return 'часа';
        if ($n1 === 1) return 'час';
        return 'часов';
    }

    public function isNamed(): bool
    {
        return $this->type === self::TYPE_NAMED;
    }
}
