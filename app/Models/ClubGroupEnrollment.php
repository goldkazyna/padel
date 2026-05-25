<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroupEnrollment extends Model
{
    protected $fillable = [
        'group_member_id', 'sessions', 'amount', 'is_paid', 'payment_method', 'created_by',
    ];

    protected $casts = [
        'sessions' => 'integer',
        'amount' => 'decimal:2',
        'is_paid' => 'boolean',
    ];

    public function member() { return $this->belongsTo(ClubGroupMember::class, 'group_member_id'); }
}
