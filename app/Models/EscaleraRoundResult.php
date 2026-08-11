<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Результат игрока за раунд «Ladder»: место на корте, позиция в общем
 * строю и баллы. Нужен для истории движения и колонки «изменение позиции».
 */
class EscaleraRoundResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'escalera_round_id',
        'user_id',
        'court_number',
        'place_on_court',
        'overall_position',
        'points',
    ];

    public function round()
    {
        return $this->belongsTo(EscaleraRound::class, 'escalera_round_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
