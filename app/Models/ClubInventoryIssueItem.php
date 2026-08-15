<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Строка выдачи: что именно и сколько ушло с полки.
 *
 * Название и цена лежат снимком — позицию справочника могут переименовать
 * или удалить, а выдача должна остаться читаемой. Тот же приём, что в
 * инвентаре брони корта.
 */
class ClubInventoryIssueItem extends Model
{
    protected $fillable = [
        'club_inventory_issue_id',
        'club_inventory_item_id',
        'name',
        'price',
        'quantity',
        'returned_at',
        'returned_by',
    ];

    protected $casts = [
        'price' => 'integer',
        'quantity' => 'integer',
        'returned_at' => 'datetime',
    ];

    public function issue()
    {
        return $this->belongsTo(ClubInventoryIssue::class, 'club_inventory_issue_id');
    }

    public function item()
    {
        return $this->belongsTo(ClubInventoryItem::class, 'club_inventory_item_id');
    }

    public function returnedBy()
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /** Ещё на руках. */
    public function scopeOpen($query)
    {
        return $query->whereNull('returned_at');
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }
}
