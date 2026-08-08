<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Позиция инвентаря клуба: аренда ракетки, мячи и прочее платное,
 * не связанное с кортами. На этом этапе — только справочник.
 */
class ClubInventoryItem extends Model
{
    protected $fillable = [
        'club_id',
        'name',
        'price',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    /** Только позиции, доступные к продаже. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
