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
        'cover',
        'description',
        'city',
        'is_active',
        'is_test',
        'is_community',
        'coming_soon',
        'hide_phones',
        'booking_cancel_hours',
        'payment_url',
        'telegram_url',
        'instagram_url',
        'offer_agreement',
        'privacy_policy',
        'goods_description',
        'card_payment_description',
        'online_payment_enabled',
        'allow_booking_without_payment',
        'auto_conduct_group_sessions',
        'plexy_api_key',
        'plexy_merchant_id',
        'plexy_webhook_secret',
        'features',
        'telegram_channel_id',
        'telegram_bot_token',
    ];

    /** Секреты не отдаём в API-ответах. */
    protected $hidden = [
        'plexy_api_key',
        'plexy_webhook_secret',
        'telegram_bot_token',
    ];

    /** Эффективный API-ключ Plexy: ключ клуба или дефолт из env. */
    public function plexyApiKey(): ?string
    {
        return $this->plexy_api_key ?: config('services.plexy.key');
    }

    /** Эффективный merchant id Plexy: клуба или дефолт из env. */
    public function plexyMerchantId(): ?string
    {
        return $this->plexy_merchant_id ?: config('services.plexy.merchant_id');
    }

    /** Секрет для проверки вебхука: клуба или дефолт из env. */
    public function plexyWebhookSecret(): ?string
    {
        return $this->plexy_webhook_secret ?: config('services.plexy.webhook_secret');
    }

    /** Готов ли клуб принимать онлайн-оплату через Plexy. */
    public function hasPlexyConfigured(): bool
    {
        return $this->online_payment_enabled && !empty($this->plexyApiKey());
    }

    protected $casts = [
        'is_active' => 'boolean',
        'is_test' => 'boolean',
        'is_community' => 'boolean',
        'coming_soon' => 'boolean',
        'hide_phones' => 'boolean',
        'online_payment_enabled' => 'boolean',
        'allow_booking_without_payment' => 'boolean',
        'auto_conduct_group_sessions' => 'boolean',
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

    /**
     * Может ли клуб менять/подтверждать уровни игроков.
     * Завязано на фичу «Пользователи» — тот же грант, что открывает раздел
     * игроков с правкой уровней. От него же зависит авто-верификация
     * после турнира и при загрузке аватара.
     */
    public function canVerifyLevels(): bool
    {
        return $this->hasFeature('users');
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