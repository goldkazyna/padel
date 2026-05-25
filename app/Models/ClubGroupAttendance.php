<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroupAttendance extends Model
{
    protected $table = 'club_group_attendance';

    protected $fillable = ['session_id', 'group_member_id', 'attended', 'charged', 'note'];

    protected $casts = [
        'attended' => 'boolean',
        'charged' => 'boolean',
    ];

    public function session() { return $this->belongsTo(ClubGroupSession::class, 'session_id'); }
    public function member() { return $this->belongsTo(ClubGroupMember::class, 'group_member_id'); }
}
