<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Смена менеджера. Персональная: если за день работают двое, у каждого
 * своя смена со своими чек-листами.
 */
class Shift extends Model
{
    use HasFactory;

    /** Часовой пояс клуба — в нём смены показываются людям. */
    public const TZ = 'Asia/Almaty';

    protected $fillable = [
        'club_id',
        'user_id',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function results()
    {
        return $this->hasMany(ShiftChecklistResult::class);
    }

    /**
     * Время клуба, а не сервера: смены пишутся в UTC, а живёт клуб по
     * Алматы (+5). Без перевода админ видит смену на пять часов раньше.
     */
    public function openedAtLocal(): \Illuminate\Support\Carbon
    {
        return $this->opened_at->timezone(self::TZ);
    }

    public function closedAtLocal(): ?\Illuminate\Support\Carbon
    {
        return $this->closed_at?->timezone(self::TZ);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Смена открыта не сегодня — менеджер забыл её закрыть.
     *
     * Считаем по дате клуба: ночная смена в UTC попадает на вчера, забытой
     * она от этого не становится.
     */
    public function isStale(): bool
    {
        return $this->isOpen()
            && !$this->openedAtLocal()->isSameDay(now(self::TZ));
    }

    /** Сколько длилась смена, в минутах. Для журнала админа. */
    public function durationMinutes(): ?int
    {
        if (!$this->closed_at) {
            return null;
        }

        return $this->opened_at->diffInMinutes($this->closed_at);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }
}
