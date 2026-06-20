<?php

namespace App\Services;

use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
use Carbon\Carbon;

class CourtScheduleService
{
    /**
     * Генерирует сетку временных слотов для календарной даты.
     * Для ночных кортов (close < open): сначала ночные слоты (00:00–close), потом дневные (open–24:00).
     */
    public function generateTimeSlots(Court $court, ?string $date = null): array
    {
        $slots = [];
        $duration = $court->slot_duration;
        $openMin = $this->timeToMinutes($court->open_time);
        $closeMin = $this->timeToMinutes($court->close_time);

        if ($court->crossesMidnight()) {
            // Ночные слоты: 00:00 → close_time
            for ($m = 0; $m + $duration <= $closeMin; $m += $duration) {
                $time = $this->minutesToTime($m);
                $slots[] = ['time' => $time, 'price' => $this->priceForTime($court, $date, $time)];
            }
            // Дневные слоты: open_time → полночь
            for ($m = $openMin; $m + $duration <= 24 * 60; $m += $duration) {
                $time = $this->minutesToTime($m);
                $slots[] = ['time' => $time, 'price' => $this->priceForTime($court, $date, $time)];
            }
        } else {
            for ($m = $openMin; $m + $duration <= $closeMin; $m += $duration) {
                $time = $this->minutesToTime($m);
                $slots[] = ['time' => $time, 'price' => $this->priceForTime($court, $date, $time)];
            }
        }

        return $slots;
    }

    /**
     * Строит расписание корта на дату.
     * $bookings/$blocks — опционально, для batch-режима (недельный вид).
     * Если не переданы, делаются отдельные запросы на дату.
     */
    public function buildSchedule(Court $court, string $date, $bookings = null, $blocks = null): array
    {
        $timeSlots = $this->generateTimeSlots($court, $date);

        if ($bookings === null) {
            $bookings = CourtBooking::where('court_id', $court->id)
                ->whereDate('date', $date)
                ->where('status', 'confirmed')
                ->with('coach')
                ->get();
        }

        if ($blocks === null) {
            $blocks = CourtBlock::where('court_id', $court->id)
                ->whereDate('date', $date)
                ->get();
        }

        $schedule = [];

        foreach ($timeSlots as $slot) {
            $time = $slot['time'];
            $slotStart = $this->timeToMinutes($time);

            $booking = $bookings->first(function ($b) use ($slotStart) {
                $bStart = $this->timeToMinutes($b->start_time);
                $bEnd = $this->timeToMinutes($b->end_time);
                if ($bEnd <= $bStart) $bEnd += 24 * 60;
                return $slotStart >= $bStart && $slotStart < $bEnd;
            });

            if ($booking) {
                $schedule[$time] = [
                    'status' => 'booked',
                    'price' => $slot['price'],
                    'booking' => $booking,
                ];
                continue;
            }

            $block = $blocks->first(function ($bl) use ($slotStart) {
                $blStart = $this->timeToMinutes($bl->start_time);
                $blEnd = $this->timeToMinutes($bl->end_time);
                if ($blEnd <= $blStart) $blEnd += 24 * 60;
                return $slotStart >= $blStart && $slotStart < $blEnd;
            });

            if ($block) {
                $schedule[$time] = [
                    'status' => 'blocked',
                    'price' => $slot['price'],
                    'booking' => null,
                    'block' => $block,
                ];
                continue;
            }

            $schedule[$time] = [
                'status' => 'free',
                'price' => $slot['price'],
                'booking' => null,
            ];
        }

        return $schedule;
    }

    /**
     * Считает цену бронирования по ценовым интервалам.
     */
    public function calculatePrice(Court $court, string $date, string $startTime, string $endTime): float
    {
        $total = 0;
        $current = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        if ($end->lte($current)) $end->addDay();
        $duration = $court->slot_duration;

        while ($current->lt($end)) {
            $total += $this->priceForTime($court, $date, $current->format('H:i'));
            $current->addMinutes($duration);
        }

        return $total;
    }

    /**
     * Максимальное количество свободных слотов подряд.
     */
    public function maxConsecutiveFreeSlots(Court $court, string $date, string $fromTime): int
    {
        $schedule = $this->buildSchedule($court, $date);
        $count = 0;
        $started = false;

        foreach ($schedule as $time => $slot) {
            if (!$started) {
                if ($time === $fromTime) {
                    $started = true;
                } else {
                    continue;
                }
            }

            if ($started) {
                if ($slot['status'] === 'free') {
                    $count++;
                } else {
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Можно ли забронировать диапазон.
     */
    public function canBook(Court $court, string $date, string $startTime, string $endTime, ?int $excludeBookingId = null): bool
    {
        $startMin = $this->timeToMinutes($startTime);
        $endMin = $this->timeToMinutes($endTime);
        if ($endMin <= $startMin) $endMin += 24 * 60;

        $bookings = CourtBooking::where('court_id', $court->id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->when($excludeBookingId, fn($q) => $q->where('id', '!=', $excludeBookingId))
            ->get();

        foreach ($bookings as $b) {
            $bStart = $this->timeToMinutes($b->start_time);
            $bEnd = $this->timeToMinutes($b->end_time);
            if ($bEnd <= $bStart) $bEnd += 24 * 60;
            if ($startMin < $bEnd && $endMin > $bStart) {
                return false;
            }
        }

        $blocks = CourtBlock::where('court_id', $court->id)
            ->whereDate('date', $date)
            ->get();

        foreach ($blocks as $bl) {
            $blStart = $this->timeToMinutes($bl->start_time);
            $blEnd = $this->timeToMinutes($bl->end_time);
            if ($blEnd <= $blStart) $blEnd += 24 * 60;
            if ($startMin < $blEnd && $endMin > $blStart) {
                return false;
            }
        }

        return true;
    }

    /**
     * Валидация ценовых интервалов.
     */
    public function validatePriceRanges(array $ranges, string $openTime, string $closeTime): array
    {
        $errors = [];

        if (empty($ranges)) {
            return ['Необходимо указать хотя бы один ценовой интервал'];
        }

        $openTime = Carbon::parse($openTime)->format('H:i');
        $closeTime = Carbon::parse($closeTime)->format('H:i');
        $crossesMidnight = $closeTime < $openTime;

        foreach ($ranges as &$r) {
            $r['time_from'] = Carbon::parse($r['time_from'])->format('H:i');
            $r['time_to'] = Carbon::parse($r['time_to'])->format('H:i');
        }
        unset($r);

        if (!$crossesMidnight) {
            // Обычная валидация
            usort($ranges, fn($a, $b) => strcmp($a['time_from'], $b['time_from']));

            for ($i = 0; $i < count($ranges) - 1; $i++) {
                if ($ranges[$i]['time_to'] > $ranges[$i + 1]['time_from']) {
                    $errors[] = "Интервалы пересекаются: {$ranges[$i]['time_from']}-{$ranges[$i]['time_to']} и {$ranges[$i+1]['time_from']}-{$ranges[$i+1]['time_to']}";
                }
            }

            if ($ranges[0]['time_from'] !== $openTime) {
                $errors[] = "Не покрыто время: {$openTime} — {$ranges[0]['time_from']}";
            }

            $lastEnd = $ranges[0]['time_to'];
            for ($i = 1; $i < count($ranges); $i++) {
                if ($ranges[$i]['time_from'] !== $lastEnd) {
                    $errors[] = "Не покрыто время: {$lastEnd} — {$ranges[$i]['time_from']}";
                }
                $lastEnd = $ranges[$i]['time_to'];
            }

            if ($lastEnd !== $closeTime) {
                $errors[] = "Не покрыто время: {$lastEnd} — {$closeTime}";
            }
        } else {
            // Ночной корт: нормализуем к линейной шкале от open_time
            $openMin = $this->timeToMinutes($openTime);
            $closeMin = $this->timeToMinutes($closeTime);
            $totalLength = (24 * 60 - $openMin) + $closeMin;

            $normalized = [];
            foreach ($ranges as $r) {
                $fromMin = $this->timeToMinutes($r['time_from']);
                $toMin = $this->timeToMinutes($r['time_to']);

                $fromOffset = $fromMin >= $openMin ? $fromMin - $openMin : (24 * 60 - $openMin) + $fromMin;
                $toOffset = $toMin >= $openMin ? $toMin - $openMin : (24 * 60 - $openMin) + $toMin;
                if ($toOffset <= $fromOffset) {
                    $toOffset = (24 * 60 - $openMin) + $toMin;
                }

                $normalized[] = [
                    'from' => $fromOffset,
                    'to' => $toOffset,
                    'label' => $r['time_from'] . '-' . $r['time_to'],
                ];
            }

            usort($normalized, fn($a, $b) => $a['from'] <=> $b['from']);

            for ($i = 0; $i < count($normalized) - 1; $i++) {
                if ($normalized[$i]['to'] > $normalized[$i + 1]['from']) {
                    $errors[] = "Интервалы пересекаются: {$normalized[$i]['label']} и {$normalized[$i+1]['label']}";
                }
            }

            if ($normalized[0]['from'] !== 0) {
                $errors[] = "Не покрыто время от {$openTime}";
            }

            for ($i = 1; $i < count($normalized); $i++) {
                if ($normalized[$i]['from'] !== $normalized[$i - 1]['to']) {
                    $errors[] = "Есть непокрытый промежуток в ценовых интервалах";
                }
            }

            if (end($normalized)['to'] !== $totalLength) {
                $errors[] = "Не покрыто время до {$closeTime}";
            }
        }

        return $errors;
    }

    /**
     * Цена для времени с учётом дня недели.
     * Выходные (Сб/Вс) → ищем в интервалах day_type='weekend'; если для этого
     * времени выходного интервала нет — фолбэк на будний (базовый). Будни и
     * случай без даты → будние интервалы.
     */
    private function priceForTime(Court $court, ?string $date, string $time): float
    {
        $all = $court->priceRanges;
        // Будние (базовые) — всё, что не помечено 'weekend' (включая legacy null).
        $weekday = $all->filter(fn($r) => ($r->day_type ?? 'weekday') !== 'weekend');
        $isWeekend = $date !== null && Carbon::parse($date)->isWeekend();

        if ($isWeekend) {
            $weekend = $all->filter(fn($r) => ($r->day_type ?? 'weekday') === 'weekend');
            $price = $this->findPriceForTime($weekend, $time);
            if ($price !== null) {
                return $price;
            }
            // фолбэк на будний интервал
        }

        return $this->findPriceForTime($weekday, $time) ?? 0.0;
    }

    /**
     * Ищет цену в наборе интервалов. Возвращает null, если ни один не подходит
     * (нужно для фолбэка будни↔выходные — 0 нельзя отличить от «не найдено»).
     */
    private function findPriceForTime($ranges, string $time): ?float
    {
        foreach ($ranges as $range) {
            $from = is_string($range['time_from'] ?? null) ? $range['time_from'] : $range->time_from;
            $to = is_string($range['time_to'] ?? null) ? $range['time_to'] : $range->time_to;
            $price = is_array($range) ? $range['price'] : $range->price;

            $from = Carbon::parse($from)->format('H:i');
            $to = Carbon::parse($to)->format('H:i');

            if ($to <= $from) {
                // Интервал через полночь
                if ($time >= $from || $time < $to) {
                    return (float) $price;
                }
            } else {
                if ($time >= $from && $time < $to) {
                    return (float) $price;
                }
            }
        }

        return null;
    }

    private function timeToMinutes(string $time): int
    {
        $parsed = Carbon::parse($time);
        return $parsed->hour * 60 + $parsed->minute;
    }

    private function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
