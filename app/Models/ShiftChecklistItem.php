<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Пункт чек-листа смены: шаблон клуба, из которого собирается проверка
 * при открытии и закрытии.
 */
class ShiftChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id',
        'type',
        'title',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    /** Активные пункты одного чек-листа, в порядке показа. */
    public function scopeForChecklist($query, int $clubId, string $type)
    {
        return $query->where('club_id', $clubId)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
