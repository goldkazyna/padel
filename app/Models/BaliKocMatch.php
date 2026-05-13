<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaliKocMatch extends Model
{
    use HasFactory;

    protected $table = 'bali_koc_matches';

    protected $fillable = [
        'bali_koc_round_id',
        'court_number',
        'pair1_id',
        'pair2_id',
        'pair1_games',
        'pair2_games',
        'status',
    ];

    public function round()
    {
        return $this->belongsTo(BaliKocRound::class, 'bali_koc_round_id');
    }

    public function pair1()
    {
        return $this->belongsTo(BaliKocPair::class, 'pair1_id');
    }

    public function pair2()
    {
        return $this->belongsTo(BaliKocPair::class, 'pair2_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getWinningPairIdAttribute(): ?int
    {
        if (!$this->isCompleted()) return null;
        if ($this->pair1_games > $this->pair2_games) return (int) $this->pair1_id;
        if ($this->pair2_games > $this->pair1_games) return (int) $this->pair2_id;
        return null;
    }
}
