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

    /** Строки конкретного клуба — клуб хранится на выдаче, не на строке. */
    public function scopeOfClub($query, int $clubId)
    {
        return $query->whereHas('issue', fn ($q) => $q->where('club_id', $clubId));
    }

    /**
     * Сколько единиц инвентаря клуба сейчас не вернули.
     * Считаем единицы, а не строки: две ракетки одному и одна другому — это три.
     * Отсюда берётся красный бейдж на пункте меню «Инвентарь».
     */
    public static function outstandingUnitsForClub($club): int
    {
        if (!$club) return 0;

        return (int) static::query()->ofClub($club->id)->open()->sum('quantity');
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }
}
