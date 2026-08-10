<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Отметка менеджера по пункту чек-листа.
 *
 * Галочка означает «проверил», а не «всё в порядке»: если что-то не так,
 * менеджер отмечает пункт и пишет замечание в комментарии.
 */
class ShiftChecklistResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'shift_id',
        'item_id',
        'type',
        'title_snapshot',
        'is_done',
        'comment',
    ];

    protected $casts = [
        'is_done' => 'boolean',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function item()
    {
        return $this->belongsTo(ShiftChecklistItem::class, 'item_id');
    }

    /** Пункты с замечаниями — то, ради чего админ открывает журнал. */
    public function scopeWithComment($query)
    {
        return $query->whereNotNull('comment')->where('comment', '!=', '');
    }
}
