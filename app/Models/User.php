<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\GameMatch;

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
	// Турниры в которых участвует
	public function tournaments()
	{
		return $this->belongsToMany(Tournament::class, 'tournament_participants')
					->withPivot('status')
					->withTimestamps();
	}
	
	// Матчи игрока
	public function matches()
	{
		return GameMatch::where('player1_id', $this->id)
					->orWhere('player2_id', $this->id)
					->orderBy('created_at', 'desc');
	}

	// Победы
	public function wins()
	{
		return GameMatch::where('winner_id', $this->id)->count();
	}

	// Поражения  
	public function losses()
	{
		return GameMatch::where('status', 'completed')
					->where(function($q) {
						$q->where('player1_id', $this->id)
						  ->orWhere('player2_id', $this->id);
					})
					->where('winner_id', '!=', $this->id)
					->count();
	}

	// Винрейт
	public function winRate(): float
	{
		$total = $this->wins() + $this->losses();
		if ($total === 0) return 0;
		return round(($this->wins() / $total) * 100, 1);
	}
}