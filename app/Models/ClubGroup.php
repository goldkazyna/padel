<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroup extends Model
{
    /** Ходят по пакету занятий. */
    public const TYPE_SUBSCRIPTION = 'subscription';

    /** Пришли разово попробовать. */
    public const TYPE_TRIAL = 'trial';

    protected $fillable = [
        'club_id', 'name', 'type', 'coach_id', 'price_per_session', 'coach_price_per_client',
        'capacity', 'status', 'note',
    ];

    protected $casts = [
        'price_per_session' => 'decimal:2',
        'coach_price_per_client' => 'decimal:2',
        'capacity' => 'integer',
    ];

    /** Новая группа без явного выбора — абонементная, как работало до появления поля. */
    protected $attributes = [
        'type' => self::TYPE_SUBSCRIPTION,
    ];

    public static function types(): array
    {
        return [
            self::TYPE_SUBSCRIPTION => 'Абонемент',
            self::TYPE_TRIAL => 'Пробная',
        ];
    }

    public function isTrial(): bool
    {
        return $this->type === self::TYPE_TRIAL;
    }

    public function getTypeNameAttribute(): string
    {
        return self::types()[$this->type] ?? self::types()[self::TYPE_SUBSCRIPTION];
    }

    public function club() { return $this->belongsTo(Club::class); }
    public function coach() { return $this->belongsTo(User::class, 'coach_id'); }
    public function members() { return $this->hasMany(ClubGroupMember::class, 'group_id'); }
    public function sessions() { return $this->hasMany(ClubGroupSession::class, 'group_id'); }
}
