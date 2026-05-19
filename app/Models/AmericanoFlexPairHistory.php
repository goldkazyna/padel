<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmericanoFlexPairHistory extends Model
{
    use HasFactory;

    protected $table = 'americano_flex_pair_history';

    protected $fillable = [
        'tournament_id', 'player1_id', 'player2_id',
        'times_as_partners', 'times_as_opponents',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function player1()
    {
        return $this->belongsTo(User::class, 'player1_id');
    }

    public function player2()
    {
        return $this->belongsTo(User::class, 'player2_id');
    }

    /** Нормализованная пара ключей (меньший id первым). */
    public static function normalizeIds(int $a, int $b): array
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }
}
