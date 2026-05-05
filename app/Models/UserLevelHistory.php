<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLevelHistory extends Model
{
    protected $table = 'user_level_history';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'changed_by_user_id',
        'club_id',
        'old_level',
        'new_level',
        'old_verified',
        'new_verified',
        'created_at',
    ];

    protected $casts = [
        'old_level' => 'float',
        'new_level' => 'float',
        'old_verified' => 'boolean',
        'new_verified' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }
}
