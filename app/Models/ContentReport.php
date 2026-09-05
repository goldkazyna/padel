<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Жалоба на игрока или переписку.
 *
 * Нужна не только по-человечески, но и формально: сторы не пропускают
 * приложения с перепиской между пользователями без жалобы и блокировки.
 */
class ContentReport extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_REVIEWED = 'reviewed';

    public const REASONS = ['spam', 'abuse', 'fraud', 'other'];

    protected $fillable = [
        'reporter_id', 'reportable_type', 'reportable_id', 'reason', 'comment', 'status',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
