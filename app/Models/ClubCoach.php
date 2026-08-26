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
            $ovStart = $this->toMinutes($override->start_time);
            $intervals[] = [$ovStart, $this->endMinutes($override->end_time, $ovStart)];
        } elseif ($weekSchedules->isNotEmpty()) {
            foreach ($weekSchedules as $ws) {
                $wsStart = $this->toMinutes($ws->start_time);
                $intervals[] = [$wsStart, $this->endMinutes($ws->end_time, $wsStart)];
            }
        }

        $timeSlots = [];
        foreach ($intervals as [$startMin, $endMin]) {
            for ($m = $startMin; $m + 60 <= $endMin; $m += 60) {
                $timeSlots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            }
        }

        $bookings = CourtBooking::forCoach($this->user_id)
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

    /**
     * Конец интервала в минутах, где 00:00 — это конец суток, а не ноль.
     *
     * Смена «14:00 — 00:00» превращалась в 840..0, интервал получался
     * пустым, и тренер считался занятым в любое время своей же смены.
     * Тем же способом закрываются ночные интервалы вроде 22:00 — 02:00.
     */
    private function endMinutes(string $end, int $startMin): int
    {
        $min = $this->toMinutes($end);

        return $min <= $startMin ? $min + 1440 : $min;
    }

    /**
     * Условие пересечения с интервалом для запроса по броням.
     * Конец 00:00:00 означает полночь, а не начало суток.
     */
    private function overlapping($query, string $start, string $end): void
    {
        $query->where('start_time', '<', $end)
            ->where(function ($q) use ($start) {
                $q->where('end_time', '>', $start)
                  ->orWhere('end_time', '00:00:00');
            });
    }

    public function isAvailableAt(string $date, string $startTime, string $endTime): bool
    {
        $dayOfWeek = \Carbon\Carbon::parse($date)->dayOfWeekIso;
        $startMin = $this->toMinutes($startTime);
        $endMin = $this->endMinutes($endTime, $startMin);

        $override = $this->overrides()->where('date', $date)->first();
        if ($override) {
            if (!$override->is_available) {
                if (!$override->start_time) return false;
                $ovStart = $this->toMinutes($override->start_time);
                $ovEnd = $this->endMinutes($override->end_time, $ovStart);
                if ($startMin < $ovEnd && $endMin > $ovStart) {
                    return false;
                }
            } else {
                if ($override->start_time && $override->end_time) {
                    $ovStart = $this->toMinutes($override->start_time);
                    $ovEnd = $this->endMinutes($override->end_time, $ovStart);
                    return $startMin >= $ovStart && $endMin <= $ovEnd;
                }
                return true;
            }
        }

        $schedules = $this->schedules()->where('day_of_week', $dayOfWeek)->get();
        if ($schedules->isEmpty()) return false;

        foreach ($schedules as $schedule) {
            $schStart = $this->toMinutes($schedule->start_time);
            $schEnd = $this->endMinutes($schedule->end_time, $schStart);
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
        // Бронь до полуночи хранится как 00:00:00 — по сравнению строк она
        // «раньше» своего же начала, и пересечение не находилось.
        $endBound = $endFormatted === '00:00:00' ? '23:59:59' : $endFormatted;

        $hasBooking = CourtBooking::where('coach_id', $this->user_id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->when($excludeBookingId, fn($q) => $q->where('id', '!=', $excludeBookingId))
            ->where(fn ($q) => $this->overlapping($q, $startFormatted, $endBound))
            ->exists();

        if ($hasBooking) return false;

        // Мультитренер: занят, если добавлен как доп. тренер в пересекающейся броне.
        $inMultiBooking = CourtBooking::whereDate('date', $date)
            ->where('status', 'confirmed')
            ->when($excludeBookingId, fn($q) => $q->where('id', '!=', $excludeBookingId))
            ->whereHas('coaches', fn($q) => $q->where('coach_id', $this->user_id))
            ->where(fn ($q) => $this->overlapping($q, $startFormatted, $endBound))
            ->exists();

        if ($inMultiBooking) return false;

        // Проверяем ручные блокировки
        $blocks = CoachBlock::where('club_coach_id', $this->id)
            ->whereDate('date', \Carbon\Carbon::parse($date)->format('Y-m-d'))
            ->get();

        $startMin = $this->toMinutes($startTime);
        $endMin = $this->endMinutes($endTime, $startMin);

        foreach ($blocks as $bl) {
            $blStart = $this->toMinutes($bl->start_time);
            // Блокировка до полуночи — такой же случай, что и смена.
            $blEnd = $this->endMinutes($bl->end_time, $blStart);
            if ($startMin < $blEnd && $endMin > $blStart) {
                return false;
            }
        }

        return true;
    }
}
