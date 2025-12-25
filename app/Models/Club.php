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
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Связь: админы клуба
    public function admins()
    {
        return $this->belongsToMany(User::class, 'club_admins');
    }

    // Scope: только активные
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
	// Турниры клуба
	public function tournaments()
	{
		return $this->hasMany(Tournament::class);
	}
}