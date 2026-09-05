<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Связь «амигос»: кто кого добавил.
 *
 * Односторонняя. Взаимность — это просто существование обеих строк.
 */
class PlayerFollow extends Model
{
    public $timestamps = false;

    protected $fillable = ['follower_id', 'following_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function follower()
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    public function following()
    {
        return $this->belongsTo(User::class, 'following_id');
    }
}
