<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourtBlock extends Model
{
    protected $fillable = ['court_id', 'date', 'start_time', 'end_time', 'comment'];

    protected $casts = ['date' => 'date'];

    public function court()
    {
        return $this->belongsTo(Court::class);
    }
}
