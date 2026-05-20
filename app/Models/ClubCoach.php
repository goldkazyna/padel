<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubCoach extends Model
{
    protected $fillable = [
        'club_id',
        'user_id',
        'specialization',
        'photo',
        'info',
        'certificates',
        'rating',
        'hourly_rate',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'certificates' => 'array',
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

    public function rates()
    {
        return $this->hasMany(CoachRate::class);
    }

    /**
     * Получить ставку за указанное кол-во часов.
     * Если точной ставки нет — берёт ближайшую меньшую или hourly_rate * hours.
     */
    /**
     * Получить общую стоимость тренера за указанное кол-во часов.
     * Ставка хранится за час, умножается на кол-во часов.
     */
    public function getRateForHours(int $hours): float
    {
        // Ищем ставку за час для этой длительности
        $rate = $this->rates()->where('hours', $hours)->first();
        if ($rate) return (float) $rate->rate * $hours;

        // Ближайшая меньшая длительность
        $closest = $this->rates()->where('hours', '<', $hours)->orderByDesc('hours')->first();
        if ($closest) {
            return (float) $closest->rate * $hours;
        }

        // Fallback на базовую ставку
        return ($this->hourly_rate ?? 0) * $hours;
    }

    private function toMinutes(string $time): int
    {
        $p = \Carbon\Carbon::parse($time);
        return $p->hour * 60 + $p->minute;
    }

    public function isAvailableAt(string $date, string $startTime, string $endTime): bool
    {
        $dayOfWeek = \Carbon\Carbon::parse($date)->dayOfWeekIso;
        $startMin = $this->toMinutes($startTime);
        $endMin = $this->toMinutes($endTime);

        $override = $this->overrides()->where('date', $date)->first();
        if ($override) {
            if (!$override->is_available) {
                if (!$override->start_time) return false;
                $ovStart = $this->toMinutes($override->start_time);
                $ovEnd = $this->toMinutes($override->end_time);
                if ($startMin < $ovEnd && $endMin > $ovStart) {
                    return false;
                }
            } else {
                if ($override->start_time && $override->end_time) {
                    $ovStart = $this->toMinutes($override->start_time);
                    $ovEnd = $this->toMinutes($override->end_time);
                    return $startMin >= $ovStart && $endMin <= $ovEnd;
                }
                return true;
            }
        }

        $schedules = $this->schedules()->where('day_of_week', $dayOfWeek)->get();
        if ($schedules->isEmpty()) return false;

        foreach ($schedules as $schedule) {
            $schStart = $this->toMinutes($schedule->start_time);
            $schEnd = $this->toMinutes($schedule->end_time);
            if ($startMin >= $schStart && $endMin <= $schEnd) {
                return true;
            }
        }

        return false;
    }

    public function isFreeAt(string $date, string $startTime, string $endTime, ?int $excludeBookingId = null): bool
    {
        if (!$this->isAvailableAt($date, $startTime, $endTime)) {
            return false;
        }

        $startFormatted = \Carbon\Carbon::parse($startTime)->format('H:i:s');
        $endFormatted = \Carbon\Carbon::parse($endTime)->format('H:i:s');

        $hasBooking = CourtBooking::where('coach_id', $this->user_id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->when($excludeBookingId, fn($q) => $q->where('id', '!=', $excludeBookingId))
            ->where(function ($q) use ($startFormatted, $endFormatted) {
                $q->where('start_time', '<', $endFormatted)
                  ->where('end_time', '>', $startFormatted);
            })
            ->exists();

        if ($hasBooking) return false;

        // Проверяем ручные блокировки
        $blocks = CoachBlock::where('club_coach_id', $this->id)
            ->whereDate('date', \Carbon\Carbon::parse($date)->format('Y-m-d'))
            ->get();

        $startMin = $this->toMinutes($startTime);
        $endMin = $this->toMinutes($endTime);

        foreach ($blocks as $bl) {
            $blStart = $this->toMinutes($bl->start_time);
            $blEnd = $this->toMinutes($bl->end_time);
            if ($startMin < $blEnd && $endMin > $blStart) {
                return false;
            }
        }

        return true;
    }
}
