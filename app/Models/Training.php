<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Тренировка: занятие, которое проводит тренер, а игроки на него записываются.
 *
 * Корт не бронируется — тренер договаривается о нём отдельно. Оплата не
 * учитывается: цена показывается игроку, деньги берутся на месте.
 */
class Training extends Model
{
    use HasFactory;

    protected $fillable = [
        'coach_id',
        'club_id',
        'starts_at',
        'duration_minutes',
        'price',
        'capacity',
        'description',
        'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'duration_minutes' => 'integer',
        'price' => 'integer',
        'capacity' => 'integer',
    ];

    public function coach()
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function participants()
    {
        return $this->hasMany(TrainingParticipant::class);
    }

    /** Игроки, записавшиеся на тренировку. */
    public function players()
    {
        return $this->belongsToMany(User::class, 'training_participants')
            ->withPivot(['reminded_1d_at', 'reminded_2h_at', 'reminded_1h_at'])
            ->withTimestamps();
    }

    /** Когда занятие заканчивается. */
    public function endsAt(): \Illuminate\Support\Carbon
    {
        return $this->starts_at->copy()->addMinutes((int) $this->duration_minutes);
    }

    /** Время окончания уже прошло — можно завершать. */
    public function isPast(): bool
    {
        return $this->endsAt()->isPast();
    }

    public function isPlanned(): bool
    {
        return $this->status === 'planned';
    }

    /** Есть ли куда записываться. */
    public function hasFreeSlots(): bool
    {
        return $this->participants()->count() < (int) $this->capacity;
    }

    /** Открыта ли запись: занятие не прошло, не отменено и не завершено. */
    public function isOpenForJoin(): bool
    {
        return $this->isPlanned()
            && $this->starts_at->isFuture()
            && $this->hasFreeSlots();
    }

    /** Предстоящие занятия, на которые ещё можно записаться. */
    public function scopeUpcoming($query)
    {
        return $query->where('status', 'planned')
            ->where('starts_at', '>', now());
    }
}
