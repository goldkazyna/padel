<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubGroupMember extends Model
{
    protected $fillable = ['group_id', 'client_id', 'status', 'subscription_ends_at', 'left_at', 'starts_at'];

    protected $casts = ['subscription_ends_at' => 'date', 'left_at' => 'datetime', 'starts_at' => 'date'];

    public function group() { return $this->belongsTo(ClubGroup::class, 'group_id'); }
    public function client() { return $this->belongsTo(ClubClient::class, 'client_id'); }
    public function enrollments() { return $this->hasMany(ClubGroupEnrollment::class, 'group_member_id'); }
    public function attendance() { return $this->hasMany(ClubGroupAttendance::class, 'group_member_id'); }
    public function freezes() { return $this->hasMany(ClubGroupMemberFreeze::class, 'group_member_id'); }

    public function getRemainingAttribute(): int
    {
        $bought = (int) $this->enrollments()->sum('sessions');
        $used = (int) $this->attendance()->where('charged', true)->count();
        return $bought - $used;
    }

    /** Активная заморозка, покрывающая дату (границы включительно), либо null. */
    public function activeFreezeOn($date): ?ClubGroupMemberFreeze
    {
        $d = \Illuminate\Support\Carbon::parse($date)->toDateString();
        return $this->freezes()
            ->whereDate('freeze_from', '<=', $d)
            ->whereDate('freeze_until', '>=', $d)
            ->first();
    }

    /** Заморожен ли участник на указанную дату. */
    public function isFrozenOn($date): bool
    {
        return $this->activeFreezeOn($date) !== null;
    }

    /**
     * Начал ли участник ходить к указанной дате.
     * До starts_at он занимает место, но занятия не списываются.
     * Нет даты начала → считаем, что ходит с самого начала.
     */
    public function hasStartedOn($date): bool
    {
        if ($this->starts_at === null) {
            return true;
        }
        $d = \Illuminate\Support\Carbon::parse($date)->toDateString();
        return $d >= $this->starts_at->toDateString();
    }
}
