<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Короткий матч «Эскалера»: три на корт за раунд, каждый — своя пара
 * из четвёрки игроков корта (каждый с каждым в паре).
 */
class EscaleraMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'escalera_round_court_id',
        'match_number',
        'team1_player1_id',
        'team1_player2_id',
        'team2_player1_id',
        'team2_player2_id',
        'team1_score',
        'team2_score',
        'status',
    ];

    public function court()
    {
        return $this->belongsTo(EscaleraRoundCourt::class, 'escalera_round_court_id');
    }

    // Игроки обеих пар: под этими именами их ждут общие партиалы модалок
    // ввода счёта (club.tournaments.partials._modal_score и _modal_edit_score).
    public function team1Player1()
    {
        return $this->belongsTo(User::class, 'team1_player1_id');
    }

    public function team1Player2()
    {
        return $this->belongsTo(User::class, 'team1_player2_id');
    }

    public function team2Player1()
    {
        return $this->belongsTo(User::class, 'team2_player1_id');
    }

    public function team2Player2()
    {
        return $this->belongsTo(User::class, 'team2_player2_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
