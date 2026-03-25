<?php

namespace App\Services;

use App\Models\Court;
use App\Models\CourtBooking;
use App\Models\CourtBlock;
use Carbon\Carbon;

class CourtScheduleService
{
    /**
     * Генерирует сетку временных слотов из настроек корта.
     */
    public function generateTimeSlots(Court $court): array
    {
        $slots = [];
        $start = Carbon::parse($court->open_time);
        $end = Carbon::parse($court->close_time);
        $duration = $court->slot_duration;

        $ranges = $court->priceRanges->sortBy('time_from');

        while ($start->copy()->addMinutes($duration)->lte($end)) {
            $timeStr = $start->format('H:i');
            $price = $this->getPriceForTime($ranges, $timeStr);

            $slots[] = [
                'time' => $timeStr,
                'price' => $price,
            ];

            $start->addMinutes($duration);
        }

        return $slots;
    }

    /**
     * Строит расписание корта на дату.
     */
    public function buildSchedule(Court $court, string $date): array
    {
        $timeSlots = $this->generateTimeSlots($court);

        $bookings = CourtBooking::where('court_id', $court->id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->get();

        $blocks = CourtBlock::where('court_id', $court->id)
            ->whereDate('date', $date)
            ->get();

        $schedule = [];

        foreach ($timeSlots as $slot) {
            $time = $slot['time']; // H:i format
            $slotStartMinutes = Carbon::parse($time)->hour * 60 + Carbon::parse($time)->minute;
            $slotEndMinutes = $slotStartMinutes + $court->slot_duration;

            $booking = $bookings->first(function ($b) use ($slotStartMinutes, $slotEndMinutes) {
                $bStartMinutes = Carbon::parse($b->start_time)->hour * 60 + Carbon::parse($b->start_time)->minute;
                $bEndMinutes = Carbon::parse($b->end_time)->hour * 60 + Carbon::parse($b->end_time)->minute;
                return $slotStartMinutes >= $bStartMinutes && $slotStartMinutes < $bEndMinutes;
            });

            if ($booking) {
                $schedule[$time] = [
                    'status' => 'booked',
                    'price' => $slot['price'],
                    'booking' => $booking,
                ];
                continue;
            }

            $block = $blocks->first(function ($bl) use ($slotStartMinutes, $slotEndMinutes) {
                $blStartMinutes = Carbon::parse($bl->start_time)->hour * 60 + Carbon::parse($bl->start_time)->minute;
                $blEndMinutes = Carbon::parse($bl->end_time)->hour * 60 + Carbon::parse($bl->end_time)->minute;
                return $slotStartMinutes >= $blStartMinutes && $slotStartMinutes < $blEndMinutes;
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
    public function calculatePrice(Court $court, string $startTime, string $endTime): float
    {
        $ranges = $court->priceRanges->sortBy('time_from');
        $total = 0;
        $current = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        $duration = $court->slot_duration;

        while ($current->lt($end)) {
            $total += $this->getPriceForTime($ranges, $current->format('H:i'));
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
    public function canBook(Court $court, string $date, string $startTime, string $endTime): bool
    {
        $startFormatted = Carbon::parse($startTime)->format('H:i:s');
        $endFormatted = Carbon::parse($endTime)->format('H:i:s');

        $hasBooking = CourtBooking::where('court_id', $court->id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->where('start_time', '<', $endFormatted)
            ->where('end_time', '>', $startFormatted)
            ->exists();

        if ($hasBooking) return false;

        $hasBlock = CourtBlock::where('court_id', $court->id)
            ->whereDate('date', $date)
            ->where('start_time', '<', $endFormatted)
            ->where('end_time', '>', $startFormatted)
            ->exists();

        return !$hasBlock;
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
        foreach ($ranges as &$r) {
            $r['time_from'] = Carbon::parse($r['time_from'])->format('H:i');
            $r['time_to'] = Carbon::parse($r['time_to'])->format('H:i');
        }
        unset($r);

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

        return $errors;
    }

    private function getPriceForTime($ranges, string $time): float
    {
        foreach ($ranges as $range) {
            $from = is_string($range['time_from'] ?? null) ? $range['time_from'] : $range->time_from;
            $to = is_string($range['time_to'] ?? null) ? $range['time_to'] : $range->time_to;
            $price = is_array($range) ? $range['price'] : $range->price;

            $from = Carbon::parse($from)->format('H:i');
            $to = Carbon::parse($to)->format('H:i');

            if ($time >= $from && $time < $to) {
                return (float) $price;
            }
        }

        return 0;
    }
}
