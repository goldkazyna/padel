<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtPriceRange extends Model
{
    protected $fillable = ['court_id', 'day_type', 'time_from', 'time_to', 'price'];

    protected $casts = ['price' => 'decimal:2'];

    public function court()
    {
        return $this->belongsTo(Court::class);
    }
}
