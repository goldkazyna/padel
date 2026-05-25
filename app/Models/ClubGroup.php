<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroup extends Model
{
    protected $fillable = [
        'club_id', 'name', 'coach_id', 'price_per_session', 'capacity', 'status', 'note',
    ];

    protected $casts = [
        'price_per_session' => 'decimal:2',
        'capacity' => 'integer',
    ];

    public function club() { return $this->belongsTo(Club::class); }
    public function coach() { return $this->belongsTo(User::class, 'coach_id'); }
    public function members() { return $this->hasMany(ClubGroupMember::class, 'group_id'); }
    public function sessions() { return $this->hasMany(ClubGroupSession::class, 'group_id'); }
}
