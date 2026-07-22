<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Кэш AI-разбора выступления игрока в турнире. Один разбор на пару
 * (турнир, игрок) — рейтинг применяется один раз при завершении и не меняется,
 * поэтому разбор стабилен и генерируется единожды.
 */
class TournamentAiAnalysis extends Model
{
    protected $table = 'tournament_ai_analyses';

    protected $fillable = ['tournament_id', 'user_id', 'content', 'model', 'lang'];

    protected $casts = [
        'content' => 'array',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
