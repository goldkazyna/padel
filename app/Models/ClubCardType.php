<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubCardType extends Model
{
    protected $fillable = [
        'club_id',
        'name',
        'kind',
        'nominal',
        'discount_percent',
        'default_validity_days',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'nominal' => 'integer',
        'discount_percent' => 'integer',
        'default_validity_days' => 'integer',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function cards()
    {
        return $this->hasMany(ClubCard::class);
    }

    /** Счётчик занятий (списывается). */
    public function isCounter(): bool
    {
        return in_array($this->kind, ['visits', 'trainer'], true);
    }

    /** Скидочная (применяет % к цене брони). */
    public function isDiscount(): bool
    {
        return in_array($this->kind, ['discount_court', 'discount_trainer'], true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function kindName(): string
    {
        return match ($this->kind) {
            'visits' => 'Посещения корта',
            'trainer' => 'Занятия с тренером',
            'discount_court' => 'Скидка на корт',
            'discount_trainer' => 'Скидка на тренера',
            default => $this->kind,
        };
    }
}
