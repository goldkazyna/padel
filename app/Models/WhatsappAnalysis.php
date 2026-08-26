<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Сохранённый разбор дня переписки: цифры + ответ модели.
 */
class WhatsappAnalysis extends Model
{
    protected $fillable = [
        'club_id', 'date', 'metrics', 'report', 'model', 'generated_by', 'generated_at',
    ];

    protected $casts = [
        'date' => 'date',
        'metrics' => 'array',
        'report' => 'array',
        'generated_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
