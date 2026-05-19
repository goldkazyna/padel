<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmericanoFlexBye extends Model
{
    protected $fillable = ['americano_flex_round_id', 'user_id'];

    public function round()
    {
        return $this->belongsTo(AmericanoFlexRound::class, 'americano_flex_round_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
