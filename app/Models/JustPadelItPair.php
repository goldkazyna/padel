<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Фиксированная пара в «Just Padel It» (is_paired).
 * Статистика и очки не хранятся здесь — они агрегируются из JustPadelItPlayer
 * двух игроков (счёт по мячам, как в обычном КК).
 */
class JustPadelItPair extends Model
{
    protected $table = 'just_padel_it_pairs';

    protected $fillable = ['tournament_id', 'player1_id', 'player2_id'];

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

    public function getDisplayNameAttribute(): string
    {
        $n1 = $this->player1->name ?? '?';
        $n2 = $this->player2->name ?? '?';
        return "{$n1} / {$n2}";
    }
}
