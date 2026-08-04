<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\ClubCard;
use App\Models\ClubCardTransaction;
use Carbon\Carbon;

class CardsReportService
{
    /**
     * Продажи клубных карт за период: кто купил, когда и за сколько.
     * «Дата покупки» — момент выпуска карты (created_at).
     * Цена берётся из типа карты (на самой карте цена не хранится).
     */
    public function sales(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $cards = ClubCard::with(['client', 'type'])
            ->where('club_id', $club->id)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('created_at')
            ->get();

        $rows = [];
        $totPrice = 0;
        foreach ($cards as $card) {
            $price = (int) ($card->type->price ?? 0);
            $rows[] = [
                $card->client->name ?? '—',
                $card->client->phone ?? '',
                $card->type->name ?? '—',
                $card->type ? $card->type->kindName() : '—',
                $card->code,
                Carbon::parse((string) $card->created_at)->format('d.m.Y'),
                $price,
            ];
            $totPrice += $price;
        }

        return new ReportSheet(
            title: 'Продажи карт',
            headings: ['Клиент', 'Телефон', 'Тип карты', 'Вид', 'Номер карты', 'Дата покупки', 'Цена'],
            rows: $rows,
            totals: ['Итого', '', '', '', count($rows) . ' шт.', '', $totPrice],
            columnFormats: [1 => '@', 6 => '#,##0'],
        );
    }

    /**
     * Списания часов по картам за период: кто ходил, когда и сколько часов списано.
     * «Когда» — дата брони (реальное занятие), fallback — дата транзакции.
     * amount хранится отрицательным (списанные часы), нулевые/пропуски не берём.
     */
    public function charges(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $fromDay = $from->copy()->startOfDay();
        $toDay = $to->copy()->endOfDay();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $txs = ClubCardTransaction::with(['card.client', 'card.type', 'booking'])
            ->where('club_id', $club->id)
            ->where('amount', '<', 0)
            ->where(function ($q) use ($fromDay, $toDay, $fromDate, $toDate) {
                $q->whereBetween('created_at', [$fromDay, $toDay])
                    ->orWhereHas('booking', fn ($b) => $b->whereBetween('date', [$fromDate, $toDate]));
            })
            ->get();

        $items = [];
        foreach ($txs as $tx) {
            // «Отходил когда» — дата брони, иначе дата списания.
            $eventDate = $tx->booking && $tx->booking->date
                ? Carbon::parse((string) $tx->booking->date)
                : Carbon::parse((string) $tx->created_at);

            // Фильтруем по фактической дате занятия (супермножество из OR-запроса выше).
            if ($eventDate->lt($from->copy()->startOfDay()) || $eventDate->gt($to->copy()->endOfDay())) {
                continue;
            }

            $hours = abs((int) $tx->amount);
            $client = $tx->card->client ?? null;
            $type = $tx->card->type ?? null;

            $items[] = [
                'date' => $eventDate,
                'row' => [
                    $client->name ?? '—',
                    $client->phone ?? '',
                    $type->name ?? '—',
                    $tx->card->code ?? '',
                    $eventDate->format('d.m.Y'),
                    $hours,
                ],
                'hours' => $hours,
            ];
        }

        usort($items, fn ($a, $b) => $a['date'] <=> $b['date']);

        $rows = array_map(fn ($i) => $i['row'], $items);
        $totHours = array_sum(array_map(fn ($i) => $i['hours'], $items));

        return new ReportSheet(
            title: 'Списания часов',
            headings: ['Клиент', 'Телефон', 'Тип карты', 'Номер карты', 'Дата занятия', 'Часов'],
            rows: $rows,
            totals: ['Итого', '', '', '', count($rows) . ' списаний', $totHours],
            columnFormats: [1 => '@', 5 => '#,##0'],
        );
    }
}
