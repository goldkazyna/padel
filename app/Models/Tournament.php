<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\GameMatch;

class Tournament extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id',
        'name',
        'description',
        'start_date',
        'registration_deadline',
        'min_level',
        'max_level',
        'max_participants',
        'price',
        'status',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'registration_deadline' => 'datetime',
        'min_level' => 'decimal:2',
        'max_level' => 'decimal:2',
        'price' => 'decimal:2',
    ];

    // Связи
    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'tournament_participants')
                    ->withPivot('status')
                    ->withTimestamps();
    }

    // Проверки статуса
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isFull(): bool
    {
        return $this->participants()->count() >= $this->max_participants;
    }

    public function canRegister(User $user): bool
    {
        // Турнир открыт
        if (!$this->isOpen()) return false;
        
        // Не переполнен
        if ($this->isFull()) return false;
        
        // Дедлайн не прошёл
        if (now() > $this->registration_deadline) return false;
        
        // Уровень подходит
        if ($user->level < $this->min_level || $user->level > $this->max_level) return false;
        
        // Ещё не зарегистрирован
        if ($this->participants()->where('user_id', $user->id)->exists()) return false;
        
        return true;
    }

    public function isRegistered(User $user): bool
    {
        return $this->participants()->where('user_id', $user->id)->exists();
    }

    // Статус текстом
    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'draft' => 'Черновик',
            'open' => 'Открыта регистрация',
            'closed' => 'Регистрация закрыта',
            'in_progress' => 'Идёт турнир',
            'completed' => 'Завершён',
            'cancelled' => 'Отменён',
            default => $this->status,
        };
    }

    // Цвет статуса для badge
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'draft' => 'secondary',
            'open' => 'success',
            'closed' => 'warning',
            'in_progress' => 'primary',
            'completed' => 'info',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }
	public function matches()
	{
		return $this->hasMany(GameMatch::class);
	}
}