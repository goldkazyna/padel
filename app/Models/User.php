<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'birth_date',
        'gender',
        'role',
        'rating',
        'level',
        'telegram_id',
        'last_played_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'birth_date' => 'date',
        'last_played_at' => 'datetime',
        'level' => 'decimal:2',
    ];

    // Связь: клубы где юзер админ
    public function adminClubs()
    {
        return $this->belongsToMany(Club::class, 'club_admins');
    }

    // Проверки ролей
    public function isPlayer(): bool
    {
        return $this->role === 'player';
    }

    public function isClubAdmin(): bool
    {
        return $this->role === 'club_admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    // Полное имя
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    // Уровень текстом
    public function getLevelNameAttribute(): string
    {
        if ($this->level < 2) return 'Начинающий';
        if ($this->level < 3) return 'Любитель';
        if ($this->level < 4) return 'Средний';
        if ($this->level < 5) return 'Продвинутый';
        return 'Про';
    }
}