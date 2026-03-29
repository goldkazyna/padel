<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubCoach extends Model
{
    protected $fillable = [
        'club_id',
        'user_id',
        'specialization',
        'hourly_rate',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function schedules()
    {
        return $this->hasMany(CoachSchedule::class);
    }

    public function overrides()
    {
        return $this->hasMany(CoachScheduleOverride::class);
    }

    public function blocks()
    {
        return $this->hasMany(CoachBlock::class);
    }

    public function isAvailableAt(string $date, string $startTime, string $endTime): bool
    {
        $dayOfWeek = \Carbon\Carbon::parse($date)->dayOfWeekIso;

        $override = $this->overrides()->where('date', $date)->first();
        if ($override) {
            if (!$override->is_available) {
                if (!$override->start_time) return false;
                if ($startTime < $override->end_time && $endTime > $override->start_time) {
                    return false;
                }
            } else {
                if ($override->start_time && $override->end_time) {
                    return $startTime >= $override->start_time && $endTime <= $override->end_time;
                }
                return true;
            }
        }

        $schedule = $this->schedules()->where('day_of_week', $dayOfWeek)->first();
        if (!$schedule) return false;

        return $startTime >= $schedule->start_time && $endTime <= $schedule->end_time;
    }

    public function isFreeAt(string $date, string $startTime, string $endTime): bool
    {
        if (!$this->isAvailableAt($date, $startTime, $endTime)) {
            return false;
        }

        $hasBooking = CourtBooking::where('coach_id', $this->user_id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where('start_time', '<', $endTime)
                  ->where('end_time', '>', $startTime);
            })
            ->exists();

        return !$hasBooking;
    }
}
