<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubCard extends Model
{
    protected $fillable = [
        'club_id',
        'club_card_type_id',
        'club_client_id',
        'code',
        'balance',
        'initial_balance',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'balance' => 'integer',
        'initial_balance' => 'integer',
    ];

    public function type()
    {
        return $this->belongsTo(ClubCardType::class, 'club_card_type_id');
    }

    public function client()
    {
        return $this->belongsTo(ClubClient::class, 'club_client_id');
    }

    public function transactions()
    {
        return $this->hasMany(ClubCardTransaction::class)->latest();
    }

    public function isCounter(): bool
    {
        return $this->type && $this->type->isCounter();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Карта актуальна: активна, не истекла, и (для счётчиков) есть остаток. */
    public function isActual(): bool
    {
        if ($this->status !== 'active') return false;
        if ($this->isExpired()) return false;
        if ($this->isCounter() && (int) $this->balance <= 0) return false;
        return true;
    }
}
