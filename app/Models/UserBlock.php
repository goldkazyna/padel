<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Блокировка игрока.
 *
 * Действует в обе стороны по эффекту: заблокированный не пишет и не видит
 * активность, а связи «амигос» между этими двумя удаляются совсем.
 */
class UserBlock extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'blocked_user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function blocked()
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }

    /** Есть ли блокировка в любую сторону между двумя игроками. */
    public static function betweenExists(int $a, int $b): bool
    {
        return static::where(function ($q) use ($a, $b) {
            $q->where('user_id', $a)->where('blocked_user_id', $b);
        })->orWhere(function ($q) use ($a, $b) {
            $q->where('user_id', $b)->where('blocked_user_id', $a);
        })->exists();
    }
}
