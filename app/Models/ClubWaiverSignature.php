<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Подпись игрока под отказом от ответственности конкретного клуба.
 *
 * Одна на клуб и навсегда: клуб может править текст, но уже собранные
 * подписи остаются как есть — вместе с тем текстом, который человек видел.
 */
class ClubWaiverSignature extends Model
{
    protected $fillable = [
        'club_id',
        'user_id',
        'full_name',
        'phone',
        'waiver_text',
        'signature_path',
        'signed_at',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
