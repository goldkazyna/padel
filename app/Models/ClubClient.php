<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubClient extends Model
{
    /**
     * Поиск клиента по телефону в любом написании.
     *
     * Номера в базе лежат вперемешку: «77012223344», «+7 (701) 222-33-44»,
     * «+7 701 222 33 44». Сравнение по концу строки на форматированных
     * ломалось — «+7 707 889 50 22» заканчивается на «50 22», а не на
     * десять цифр подряд, и клиент с клубной картой просто не находился.
     */
    public function scopeByPhone($query, ?string $phone)
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) < 5) {
            // Пустой результат вместо совпадения со всеми подряд.
            return $query->whereRaw('1 = 0');
        }

        $normalized = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '(', ''), ')', ''), '-', ''), '.', '')";

        return $query->whereRaw("{$normalized} LIKE ?", ['%' . substr($digits, -10)]);
    }

    protected $fillable = [
        'club_id',
        'user_id',
        'name',
        'phone',
        'email',
        'card_number',
        'note',
        'gender',
        'birth_date',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    /** Пользователь приложения, привязанный к этому клиенту (может быть null). */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class, 'client_id');
    }
}
