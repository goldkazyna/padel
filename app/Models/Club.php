<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'logo',
        'description',
        'city',
        'is_active',
        'is_test',
        'is_community',
        'hide_phones',
        'booking_cancel_hours',
        'payment_url',
        'features',
        'telegram_channel_id',
        'telegram_bot_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_test' => 'boolean',
        'is_community' => 'boolean',
        'hide_phones' => 'boolean',
        'features' => 'array',
    ];

    protected static array $featureDefaults = [
        'coach_booking' => false,
    ];

    public function hasFeature(string $feature): bool
    {
        $features = $this->features ?? [];
        $default = static::$featureDefaults[$feature] ?? true;
        return ($features[$feature] ?? $default) === true;
    }

    // Связь: админы клуба
    public function admins()
    {
        return $this->belongsToMany(User::class, 'club_admins');
    }
	// Связь: модераторы клуба
	public function moderators()
	{
		return $this->belongsToMany(User::class, 'club_moderators')
			->withPivot('tournaments_full_access', 'can_view_activity_log');
	}

    public function coaches()
    {
        return $this->belongsToMany(User::class, 'club_coaches')->withPivot('specialization', 'hourly_rate');
    }

    public function clubCoaches()
    {
        return $this->hasMany(ClubCoach::class);
    }
    // Scope: только активные
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Scope: исключить тестовые клубы (для публичных списков)
    public function scopeNotTest($query)
    {
        return $query->where('is_test', false);
    }
	// Корты клуба
	public function courts()
	{
		return $this->hasMany(Court::class);
	}
	// Турниры клуба
	public function tournaments()
	{
		return $this->hasMany(Tournament::class);
	}
}