<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TournamentTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'tournament_id',
        'player1_id',
        'player2_id',
        'name',
        'seed',
        'rating_avg',
        'rating_before',
        'rating_after',
        'status',
    ];

    // Статусы
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function player1()
    {
        return $this->belongsTo(User::class, 'player1_id');
    }

    public function player2()
    {
        return $this->belongsTo(User::class, 'player2_id');
    }

    public function standings()
    {
        return $this->hasMany(TournamentTeamStanding::class, 'team_id');
    }

    public function getNameAttribute($value)
    {
        if ($value) return $value;
        return $this->player1->name . ' / ' . $this->player2->name;
    }

    public function getFullNameAttribute()
    {
        return $this->player1->name . ' / ' . $this->player2->name;
    }

    public function getPlayersAttribute()
    {
        return [$this->player1, $this->player2];
    }

    // Проверки статуса
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    // Скоупы
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}