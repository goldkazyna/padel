<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\ClubClient;
use App\Models\CourtBooking;
use Carbon\Carbon;

class ClientsReportService
{
    use CalculatesBookingRevenue;

    /** @return string[] phone digit variants for matching */
    private function phoneVariants(?string $phone): array
    {
        if (!$phone) return [];
        $digits = preg_replace('/\D/', '', $phone);
        $variants = [$digits];
        if (strlen($digits) === 10) $variants[] = '7' . $digits;
        elseif (strlen($digits) === 11 && $digits[0] === '7') $variants[] = substr($digits, 1);
        return array_values(array_unique($variants));
    }

    public function visits(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $courtIds = $club->courts()->pluck('id');
        $clients = ClubClient::where('club_id', $club->id)->orderBy('name')->get();

        $rows = []; $totVisits = 0; $totAmount = 0;
        foreach ($clients as $client) {
            $variants = $this->phoneVariants($client->phone);
            if (empty($variants)) continue;

            $bookings = CourtBooking::whereIn('court_id', $courtIds)
                ->where('status', 'confirmed')
                ->whereDate('date', '>=', $from->toDateString())
                ->whereDate('date', '<=', $to->toDateString())
                ->where(function ($q) use ($variants) {
                    foreach ($variants as $v) {
                        $q->orWhereRaw("REPLACE(REPLACE(REPLACE(client_phone,'+',''),' ',''),'-','') = ?", [$v]);
                    }
                })
                ->get();

            if ($bookings->isEmpty()) continue;

            $amount = $bookings->sum(fn ($b) => $this->bookingRevenue($b, $club->id));
            $last = $bookings->max('date');
            $rows[] = [
                $client->name,
                $client->phone,
                $bookings->count(),
                round($amount, 2),
                Carbon::parse((string) $last)->format('d.m.Y'),
            ];
            $totVisits += $bookings->count();
            $totAmount += $amount;
        }

        usort($rows, fn ($a, $b) => $b[2] <=> $a[2]);

        return new ReportSheet(
            title: 'Посещения клиентов',
            headings: ['Клиент', 'Телефон', 'Визитов', 'Сумма', 'Последний визит'],
            rows: $rows,
            totals: ['Итого', '', $totVisits, round($totAmount, 2), ''],
            columnFormats: [1 => '@', 3 => '#,##0'],
        );
    }
}
