<?php

namespace App\Services;

use App\Models\CourtBooking;
use App\Models\Tournament;

/**
 * Цена турнирных броней корта.
 *
 * Сумма за турнир на дату = цена турнира × число оплативших участников.
 * Она делится поровну между всеми подтверждёнными бронями турнира на эту дату.
 */
class TournamentBookingPriceService
{
    /**
     * Сколько всего должен стоить турнир на дату (до деления между кортами).
     */
    public function totalForDate(Tournament $tournament): float
    {
        // approvedParticipantsCount() сам различает личные турниры (статус
        // 'registered') и командные ('approved'-пары × 2) — свою логику не пишем.
        return (float) $tournament->price * $tournament->approvedParticipantsCount();
    }

    /**
     * Разложить сумму турнира по его броням на дату.
     * Возвращает true, если хотя бы одна цена изменилась.
     */
    public function syncForDate(Tournament $tournament, string $date): bool
    {
        $bookings = CourtBooking::where('tournament_id', $tournament->id)
            ->whereDate('date', $date)
            ->where('status', 'confirmed')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        if ($bookings->isEmpty()) {
            return false;
        }

        $total = $this->totalForDate($tournament);
        $count = $bookings->count();

        // Делим до копеек, остаток отдаём первой броне, чтобы сумма сошлась.
        $share = floor($total / $count * 100) / 100;
        $remainder = round($total - $share * $count, 2);

        $changed = false;
        foreach ($bookings as $i => $booking) {
            $price = $i === 0 ? round($share + $remainder, 2) : $share;
            if ((float) $booking->price !== $price) {
                // Скидка турнирной броне не применяется — цену задаёт турнир.
                $booking->update(['price' => $price, 'discount' => 0]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Пересчитать набор, в который входит бронь. Безопасно вызывать
     * для любой брони — не турнирные просто игнорируются.
     */
    public function syncForBooking(CourtBooking $booking): void
    {
        if (!$booking->tournament_id) {
            return;
        }

        $tournament = Tournament::find($booking->tournament_id);
        if ($tournament) {
            $this->syncForDate($tournament, $booking->date->format('Y-m-d'));
        }
    }
}
