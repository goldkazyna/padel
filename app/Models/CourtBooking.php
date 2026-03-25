<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtBooking extends Model
{
    protected $fillable = [
        'court_id',
        'date',
        'start_time',
        'end_time',
        'client_name',
        'client_phone',
        'status',
        'cancelled_at',
        'booked_by',
        'price',
    ];

    protected $casts = [
        'date' => 'date',
        'cancelled_at' => 'datetime',
        'price' => 'decimal:2',
    ];

    public function court()
    {
        return $this->belongsTo(Court::class);
    }

    public function bookedByUser()
    {
        return $this->belongsTo(User::class, 'booked_by');
    }
}
