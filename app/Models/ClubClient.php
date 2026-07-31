<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubClient extends Model
{
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
