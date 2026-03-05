<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourtSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'court_id',
        'date',
        'start_time',
        'end_time',
        'price',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
        'price' => 'decimal:2',
    ];

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function booking()
    {
        return $this->hasOne(CourtBooking::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available'
            && !$this->booking()->where('status', 'confirmed')->exists();
    }

    public function isBooked(): bool
    {
        return $this->booking()->where('status', 'confirmed')->exists();
    }
}
