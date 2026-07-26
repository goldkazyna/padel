<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Лог успешных веб-входов: кто, когда, с какого устройства (cookie device_id),
 * IP и браузер. Помогает увидеть, когда один аккаунт используют с разных
 * устройств (шаринг логина).
 */
class LoginLog extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'ip',
        'user_agent',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
