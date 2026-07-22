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
            ->with(['court', 'coaches'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Тренеры брони как отдельные «ноги» выплаты. Индивидуальная бронь со спаррингом
     * (несколько тренеров в пивоте) → по одной ноге на каждого тренера с его ценой/оплатой.
     * Групповая бронь и старые брони без пивота → одна нога по coach_id.
     *
     * @return array<int, array{coach_id:int, coach_price:mixed, coach_paid:bool}>
     */
    private function coachLegs(CourtBooking $b): array
    {
        if ($b->booking_type !== 'group' && $b->coaches->isNotEmpty()) {
            return $b->coaches->map(fn($pc) => [
                'coach_id' => (int) $pc->coach_id,
                'coach_price' => $pc->coach_price,
                'coach_paid' => (bool) $pc->coach_paid,
            ])->all();
        }
        return [[
            'coach_id' => (int) $b->coach_id,
            'coach_price' => $b->coach_price,
            'coach_paid' => (bool) $b->coach_paid,
        ]];
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
            $h = $this->hours($b->start_time, $b->end_time);
            $type = in_array($b->booking_type, ['group', 'individual', 'soft', 'tournament'], true)
                ? $b->booking_type : 'other';
            foreach ($this->coachLegs($b) as $leg) {
                $id = $leg['coach_id'];
                $agg[$id] ??= $blank;
                $prof = $profiles->get($id);
                if ($leg['coach_price'] !== null) {
                    // Зафиксированная сумма (замороженная при проведении / ручная).
                    $earn = (float) $leg['coach_price'];
                } elseif ($b->booking_type === 'group' && $prof && $prof->rate_group !== null) {
                    // Группа ещё не проведена — прикидка по текущей групповой ставке.
                    $earn = (float) $prof->rate_group * $h;
                } else {
                    $earn = $prof?->getRateForHours((int) floor($h)) ?? 0.0;
                }
                $agg[$id][$type] += $earn;
            }
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
            ->with('courtBooking')
            ->get();
        foreach ($sessions as $s) {
            // Замороженная при проведении сумма имеет приоритет; иначе — по текущей ставке.
            if ($s->courtBooking && $s->courtBooking->coach_price !== null) {
                $group += (float) $s->courtBooking->coach_price;
            } else {
                $rate = (float) ($profiles->get($s->coach_id)?->rate_group ?? 0);
                $group += $rate * $this->hours($s->start_time, $s->end_time);
            }
        }

        // Индивидуальные (всё, кроме групповых): coach_price либо ставка × часы.
        // Спарринг с несколькими тренерами — суммируем всех тренеров пивота.
        $bookings = CourtBooking::whereIn('court_id', $courtIds)
            ->where('status', 'confirmed')
            ->whereNotNull('coach_id')
            ->where(fn($q) => $q->where('booking_type', '!=', 'group')->orWhereNull('booking_type'))
            ->whereDate('date', '>=', $fromD)
            ->whereDate('date', '<=', $toD)
            ->with('coaches')
            ->get();
        foreach ($bookings as $b) {
            $h = $this->hours($b->start_time, $b->end_time);
            foreach ($this->coachLegs($b) as $leg) {
                $individual += $leg['coach_price'] !== null
                    ? (float) $leg['coach_price']
                    : ($profiles->get($leg['coach_id'])?->getRateForHours((int) floor($h)) ?? 0.0);
            }
        }

        return ['group' => $group, 'individual' => $individual];
    }

    /**
     * Неоплаченные тренеры за период: брони с назначенным тренером и
     * coach_paid = false. Строка на каждую бронь, сумма к выплате тренеру =
     * coach_price из брони, либо ставка × часы.
     */
    public function unpaid(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        // Берём все брони с тренером за период и фильтруем неоплаченные «ноги»
        // (у мультитренера оплата у каждого своя — DB-фильтр по броне не годится).
        $bookings = CourtBooking::whereIn('court_id', $club->courts()->pluck('id'))
            ->where('status', 'confirmed')
            ->whereNotNull('coach_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with(['court', 'coaches'])
            ->get();

        $names = [];
        $profiles = ClubCoach::where('club_id', $club->id)->get()->keyBy('user_id');
        $typeLabels = [
            'soft'       => 'Мягкая',
            'group'      => 'Групповая',
            'individual' => 'Индивид.',
            'tournament' => 'Турнир',
        ];

        $rows = [];
        $total = 0.0;
        foreach ($bookings as $b) {
            $h = $this->hours($b->start_time, $b->end_time);
            foreach ($this->coachLegs($b) as $leg) {
                if ($leg['coach_paid']) continue; // оплаченных не показываем
                $prof = $profiles->get($leg['coach_id']);
                if ($leg['coach_price'] !== null) {
                    // Зафиксированная сумма (замороженная при проведении / ручная).
                    $amount = (float) $leg['coach_price'];
                } elseif ($b->booking_type === 'group' && $prof && $prof->rate_group !== null) {
                    // Группа ещё не проведена — прикидка по текущей групповой ставке.
                    $amount = (float) $prof->rate_group * $h;
                } else {
                    $amount = $prof?->getRateForHours((int) floor($h)) ?? 0.0;
                }

                $rows[] = [
                    $this->formatDate($b->date),
                    Carbon::parse($b->start_time)->format('H:i') . '–' . Carbon::parse($b->end_time)->format('H:i'),
                    $b->court->name ?? '',
                    $this->coachName($leg['coach_id'], $names),
                    $b->client_name ?? '',
                    $typeLabels[$b->booking_type] ?? '',
                    round($amount, 2),
                    $b->date instanceof Carbon ? $b->date->toDateString() : (string) $b->date, // sort key
                ];
                $total += $amount;
            }
        }

        // Сортировка: по тренеру, затем по дате.
        usort($rows, fn($a, $b) => [$a[3], $a[7]] <=> [$b[3], $b[7]]);
        // Убираем вспомогательный ключ сортировки.
        $rows = array_map(fn($r) => array_slice($r, 0, 7), $rows);

        return new ReportSheet(
            title: 'Неоплаченные тренеры',
            headings: ['Дата', 'Время', 'Корт', 'Тренер', 'Клиент', 'Тип', 'Сумма тренеру'],
            rows: $rows,
            totals: ['Итого', '', '', '', '', '', round($total, 2)],
            columnFormats: [6 => '#,##0'],
        );
    }

    public function salary(Club $club, Carbon $from, Carbon $to): ReportSheet
    {
        $bookings = $this->coachBookings($club, $from, $to);
        $names = [];
        $profiles = ClubCoach::where('club_id', $club->id)->get()->keyBy('user_id');

        $agg = []; // userId => [sessions, hours, pay]
        foreach ($bookings as $b) {
            $h = $this->hours($b->start_time, $b->end_time);
            $wholeHours = (int) floor($h);
            foreach ($this->coachLegs($b) as $leg) {
                $id = $leg['coach_id'];
                $profile = $profiles->get($id);
                $pay = $profile ? $profile->getRateForHours($wholeHours) : 0.0;
                $agg[$id] ??= [0, 0.0, 0.0];
                $agg[$id][0]++;
                $agg[$id][1] += $h;
                $agg[$id][2] += $pay;
            }
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
