<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Court extends Model
{
    use HasFactory;

    protected $fillable = [
        'club_id',
        'name',
        'description',
        'is_active',
        'sort_order',
        'open_time',
        'close_time',
        'slot_duration',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'slot_duration' => 'integer',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function priceRanges()
    {
        return $this->hasMany(CourtPriceRange::class)->orderBy('time_from');
    }

    public function bookings()
    {
        return $this->hasMany(CourtBooking::class);
    }

    public function blocks()
    {
        return $this->hasMany(CourtBlock::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
