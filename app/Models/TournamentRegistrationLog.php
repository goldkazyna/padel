<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentRegistrationLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['tournament_id', 'user_id', 'action', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * Записать событие записи/отписки игрока на турнир.
     * $action: 'registered' | 'unregistered'.
     */
    public static function record(int $tournamentId, int $userId, string $action): void
    {
        self::create([
            'tournament_id' => $tournamentId,
            'user_id' => $userId,
            'action' => $action,
            'created_at' => now(),
        ]);
    }
}
