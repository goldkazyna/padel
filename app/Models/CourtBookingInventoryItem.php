<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Позиция инвентаря, выданная по брони корта. Название и цена хранятся
 * снимком: справочник могли потом изменить или позицию удалить.
 */
class CourtBookingInventoryItem extends Model
{
    protected $fillable = [
        'court_booking_id',
        'club_inventory_item_id',
        'name',
        'price',
        'quantity',
    ];

    protected $casts = [
        'price' => 'integer',
        'quantity' => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(CourtBooking::class, 'court_booking_id');
    }

    public function item()
    {
        return $this->belongsTo(ClubInventoryItem::class, 'club_inventory_item_id');
    }

    /** Стоимость строки: цена за единицу × количество. */
    public function getTotalAttribute(): int
    {
        return (int) $this->price * (int) $this->quantity;
    }
}
