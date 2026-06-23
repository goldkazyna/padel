<?php

namespace App\Reports;

use App\Models\Club;
use App\Models\ClubCoach;
use App\Models\ClubGroupSession;
use App\Models\CourtBooking;
use App\Models\User;
use Carbon\Carbon;

class CoachesReportService
{
    private function hours(string $start, string $end): float
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        if ($e->lessThanOrEqualTo($s)) {
            $e->addDay();
        }
        return round($s->floatDiffInRealHours($e), 2);
    }

    private function coachBookings(Club $club, Carbon $from, Carbon $to)
    {
        return CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereNotNull('coach_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with('court')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();
    }

    private function coachName(int $userId, array &$cache): string
    {
        if (!isset($cache[$userId])) {
            $u = User::find($userId);
            $cache[$userId] = $u
                ? ($u->name ?: trim($u->first_name . ' ' . $u->last_name))
                : "ID {$userId}";
        }
        return $cache[$userId];
    }

    private function formatDate($date): string
    {
        if ($date instanceof Carbon) {
            return $date->format('d.m.Y');
        }
        return Carbon::parse((string) $date)->format('d.m.Y');
    }

    public function usage(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $agg = []; // userId => [sessions, hours, income]
        foreach ($bookings as $b) {
            $id = $b->coach_id;
            $agg[$id] ??= [0, 0.0, 0.0];
            $agg[$id][0]++;
            $agg[$id][1] += $this->hours($b->start_time, $b->end_time);
            $agg[$id][2] += (float) $b->price - (float) $b->discount;
        }
        $rows = [];
        $tS = 0;
        $tH = 0.0;
        $tI = 0.0;
        foreach ($agg as $id => [$s, $h, $i]) {
            $rows[] = [$this->coachName($id, $names), $s, round($h, 2), round($i, 2)];
            $tS += $s;
            $tH += $h;
            $tI += $i;
        }
        usort($rows, fn($a, $b) => $b[1] <=> $a[1]);
        return new ReportSheet(
            title: 'Использование услуг (тренеры)',
            headings: ['Тренер', 'Занятий', 'Часов', 'Доход клуба'],
            rows: $rows,
            totals: ['Итого', $tS, round($tH, 2), round($tI, 2)],
            columnFormats: [2 => '#,##0.0', 3 => '#,##0'],
        );
    }

    public function sessions(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $typeLabels = [
            'soft'         => 'Мягкая',
            'group'        => 'Групповая',
            'individual'   => 'Индивид.',
            'tournament'   => 'Турнир',
        ];
        $rows = [];
        $tAmount = 0.0;
        foreach ($bookings as $b) {
            $amount = (float) $b->price - (float) $b->discount;
            $rows[] = [
                $this->formatDate($b->date),
                Carbon::parse($b->start_time)->format('H:i') . '–' . Carbon::parse($b->end_time)->format('H:i'),
                $b->court->name ?? '',
                $this->coachName($b->coach_id, $names),
                $b->client_name ?? '',
                $this->hours($b->start_time, $b->end_time),
                $typeLabels[$b->booking_type] ?? '',
                round($amount, 2),
            ];
            $tAmount += $amount;
        }
        return new ReportSheet(
            title: 'Проведённые тренировки',
            headings: ['Дата', 'Время', 'Корт', 'Тренер', 'Клиент', 'Часов', 'Тип', 'Сумма'],
            rows: $rows,
            totals: ['Итого', '', '', '', '', '', '', round($tAmount, 2)],
            columnFormats: [5 => '#,##0.0', 7 => '#,##0'],
        );
    }

    /**
     * Доход тренеров в разрезе типов брони.
     * «Заработал» = coach_price (цена тренера в брони), либо ставка×часы, если не задана.
     */
    public function incomeByType(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $profiles = ClubCoach::where('club_id', $club->id)->get()->keyBy('user_id');

        $cols = ['group', 'individual', 'soft', 'tournament', 'other'];
        $blank = array_fill_keys($cols, 0.0);
        $agg = []; // userId => [group, individual, soft, tournament, other]

        foreach ($bookings as $b) {
            $id = $b->coach_id;
            $agg[$id] ??= $blank;

            $earn = $b->coach_price !== null
                ? (float) $b->coach_price
                : ($profiles->get($id)?->getRateForHours((int) floor($this->hours($b->start_time, $b->end_time))) ?? 0.0);

            $type = in_array($b->booking_type, ['group', 'individual', 'soft', 'tournament'], true)
                ? $b->booking_type : 'other';
            $agg[$id][$type] += $earn;
        }

        $rows = [];
        $tot = $blank;
        foreach ($agg as $id => $by) {
            $sum = array_sum($by);
            $rows[] = [
                $this->coachName($id, $names),
                round($by['group'], 2), round($by['individual'], 2), round($by['soft'], 2),
                round($by['tournament'], 2), round($by['other'], 2), round($sum, 2),
            ];
            foreach ($cols as $c) {
                $tot[$c] += $by[$c];
            }
        }
        usort($rows, fn($a, $b) => $b[6] <=> $a[6]);

        return new ReportSheet(
            title: 'Доход тренеров по типам',
            headings: ['Тренер', 'Групповые', 'Индивидуальные', 'Мягкая', 'Турнир', 'Прочее', 'Итого'],
            rows: $rows,
            totals: ['Итого', round($tot['group'], 2), round($tot['individual'], 2), round($tot['soft'], 2),
                     round($tot['tournament'], 2), round($tot['other'], 2), round(array_sum($tot), 2)],
            columnFormats: [1 => '#,##0', 2 => '#,##0', 3 => '#,##0', 4 => '#,##0', 5 => '#,##0', 6 => '#,##0'],
        );
    }

    /**
     * Суммарные выплаты тренерам за период: за групповые vs за индивидуальные (без групп).
     *  - Групповые: проведённые занятия × ставка тренера за группу (rate_group ₸/час × часы),
     *    тренеру, который фактически провёл занятие (session.coach_id).
     *  - Индивидуальные (всё, кроме групповых): coach_price из брони, либо ставка × часы.
     *
     * @return array{group: float, individual: float}
     */
    public function payoutTotals(Club $club, Carbon $from, Carbon $to): array
    {
        $profiles = ClubCoach::where('club_id', $club->id)->get()->keyBy('user_id');
        $courtIds = $club->courts()->pluck('id');
        $fromD = $from->toDateString();
        $toD = $to->toDateString();

        $group = 0.0;
        $individual = 0.0;

        // Групповые: проведённые занятия, ставка за группу × часы.
        $sessions = ClubGroupSession::whereIn('court_id', $courtIds)
            ->where('status', 'held')
            ->whereNotNull('coach_id')
            ->whereDate('date', '>=', $fromD)
            ->whereDate('date', '<=', $toD)
            ->get();
        foreach ($sessions as $s) {
            $rate = (float) ($profiles->get($s->coach_id)?->rate_group ?? 0);
            $group += $rate * $this->hours($s->start_time, $s->end_time);
        }

        // Индивидуальные (всё, кроме групповых): coach_price либо ставка × часы.
        $bookings = CourtBooking::whereIn('court_id', $courtIds)
            ->where('status', 'confirmed')
            ->whereNotNull('coach_id')
            ->where(fn($q) => $q->where('booking_type', '!=', 'group')->orWhereNull('booking_type'))
            ->whereDate('date', '>=', $fromD)
            ->whereDate('date', '<=', $toD)
            ->get();
        foreach ($bookings as $b) {
            $h = $this->hours($b->start_time, $b->end_time);
            $individual += $b->coach_price !== null
                ? (float) $b->coach_price
                : ($profiles->get($b->coach_id)?->getRateForHours((int) floor($h)) ?? 0.0);
        }

        return ['group' => $group, 'individual' => $individual];
    }

    public function salary(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $profiles = ClubCoach::where('club_id', $club->id)->get()->keyBy('user_id');

        $agg = []; // userId => [sessions, hours, pay]
        foreach ($bookings as $b) {
            $h = $this->hours($b->start_time, $b->end_time);
            $profile = $profiles->get($b->coach_id);
            $wholeHours = (int) floor($h);
            $pay = $profile ? $profile->getRateForHours($wholeHours) : 0.0;

            $id = $b->coach_id;
            $agg[$id] ??= [0, 0.0, 0.0];
            $agg[$id][0]++;
            $agg[$id][1] += $h;
            $agg[$id][2] += $pay;
        }
        $rows = [];
        $tS = 0;
        $tH = 0.0;
        $tP = 0.0;
        foreach ($agg as $id => [$s, $h, $p]) {
            $rows[] = [$this->coachName($id, $names), $s, round($h, 2), round($p, 2)];
            $tS += $s;
            $tH += $h;
            $tP += $p;
        }
        usort($rows, fn($a, $b) => $b[3] <=> $a[3]);
        return new ReportSheet(
            title: 'Зарплата тренеров',
            headings: ['Тренер', 'Занятий', 'Часов', 'К начислению'],
            rows: $rows,
            totals: ['Итого', $tS, round($tH, 2), round($tP, 2)],
            columnFormats: [2 => '#,##0.0', 3 => '#,##0'],
        );
    }
}
