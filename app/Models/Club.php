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
        'booking_cancel_hours',
        'payment_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Связь: админы клуба
    public function admins()
    {
        return $this->belongsToMany(User::class, 'club_admins');
    }
	// Связь: модераторы клуба
	public function moderators()
	{
		return $this->belongsToMany(User::class, 'club_moderators');
	}
    // Scope: только активные
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
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