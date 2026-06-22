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
        'rate_group',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'rate_group' => 'decimal:2',
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
     * Построить расписание тренера на конкретный день:
     * массив слотов по часам со статусами free/booked/blocked.
     * Возвращает ['timeSlots' => [...], 'schedule' => ['HH:MM' => [...]]].
     */
    public function daySchedule(string $date): array
    {
        $dayOfWeek = \Carbon\Carbon::parse($date)->dayOfWeekIso;

        $override = $this->overrides()->whereDate('date', $date)->first();
        $weekSchedules = $this->schedules()->where('day_of_week', $dayOfWeek)->orderBy('start_time')->get();

        $intervals = [];
        if ($override && !$override->is_available && !$override->start_time) {
            // Полный выходной — слотов нет
        } elseif ($override && $override->is_available && $override->start_time) {
            $intervals[] = [$this->toMinutes($override->start_time), $this->toMinutes($override->end_time)];
        } elseif ($weekSchedules->isNotEmpty()) {
            foreach ($weekSchedules as $ws) {
                $intervals[] = [$this->toMinutes($ws->start_time), $this->toMinutes($ws->end_time)];
            }
        }

        $timeSlots = [];
        foreach ($intervals as [$startMin, $endMin]) {
            for ($m = $startMin; $m + 60 <= $endMin; $m += 60) {
                $timeSlots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            }
        }

        $bookings = CourtBooking::where('coach_id', $this->user_id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->with('court')
            ->get();

        $blocks = CoachBlock::where('club_coach_id', $this->id)
            ->whereDate('date', $date)
            ->get();

        $schedule = [];
        foreach ($timeSlots as $time) {
            $slotStart = $this->toMinutes($time);

            $booking = $bookings->first(function ($b) use ($slotStart) {
                $bStart = $this->toMinutes($b->start_time);
                $bEnd = $this->toMinutes($b->end_time);
                if ($bEnd <= $bStart) $bEnd += 1440;
                return $slotStart >= $bStart && $slotStart < $bEnd;
            });
            if ($booking) { $schedule[$time] = ['status' => 'booked', 'booking' => $booking]; continue; }

            $block = $blocks->first(function ($bl) use ($slotStart) {
                $blStart = $this->toMinutes($bl->start_time);
                $blEnd = $this->toMinutes($bl->end_time);
                if ($blEnd <= $blStart) $blEnd += 1440;
                return $slotStart >= $blStart && $slotStart < $blEnd;
            });
            if ($block) { $schedule[$time] = ['status' => 'blocked', 'block' => $block]; continue; }

            $schedule[$time] = ['status' => 'free'];
        }

        return ['timeSlots' => $timeSlots, 'schedule' => $schedule];
    }

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

    public function isFreeAt(string $date, string $startTime, string $endTime, ?int $excludeBookingId = null, bool $skipScheduleCheck = false): bool
    {
        // Для групповых занятий тренер привязан к группе, а не к слот-графику —
        // проверка регулярного расписания может быть пропущена.
        if (!$skipScheduleCheck && !$this->isAvailableAt($date, $startTime, $endTime)) {
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
