<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RatingHistory extends Model
{
    /**
     * Причина ручной правки рейтинга администратором.
     *
     * Отличить её больше нечем: у ручной правки, поединка и игры одинаково
     * пустой tournament_id. Достижения такие записи не считают — иначе
     * поднятие уровня руками выдаёт значок за игровое достижение.
     */
    public const REASON_MANUAL = 'Ручная корректировка';

    /** Кто сделал ручную правку (у турнирных записей пусто). */
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /** От какого клуба сделана правка. */
    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    /** Только то, что игрок заработал игрой, без ручных правок. */
    public function scopeFromPlay($query)
    {
        return $query->where('reason', '!=', self::REASON_MANUAL);
    }

    use HasFactory;

    protected $table = 'rating_history';

    protected $fillable = [
        'user_id',
        'changed_by_user_id',
        'club_id',
        'tournament_id',
        'rating_before',
        'rating_after',
        'change',
        'reason',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }
}