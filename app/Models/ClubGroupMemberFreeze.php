<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroupMemberFreeze extends Model
{
    protected $fillable = ['group_member_id', 'freeze_from', 'freeze_until', 'note', 'created_by'];

    protected $casts = [
        'freeze_from' => 'date',
        'freeze_until' => 'date',
    ];

    public function member()
    {
        return $this->belongsTo(ClubGroupMember::class, 'group_member_id');
    }
}
