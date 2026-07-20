<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\ClubGroupSession;
use App\Services\GroupSessionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Авто-проведение групповых занятий в клубах с включённой настройкой
 * (auto_conduct_group_sessions). Берёт запланированные занятия с прошедшим
 * временем окончания и проводит их: всем активным участникам «был», списание
 * решает GroupSessionService (не заморожен + остаток > 0). Пустой пакет — бесплатно.
 */
class AutoConductGroupSessions extends Command
{
    protected $signature = 'group-sessions:auto-conduct';
    protected $description = 'Авто-проведение групповых занятий (клубы с включённой настройкой)';

    public function handle(GroupSessionService $service): int
    {
        $now = now('Asia/Almaty');
        $conducted = 0;

        $clubs = Club::where('auto_conduct_group_sessions', true)->get();
        foreach ($clubs as $club) {
            $courtIds = $club->courts()->pluck('id');
            if ($courtIds->isEmpty()) {
                continue;
            }

            $sessions = ClubGroupSession::whereIn('court_id', $courtIds)
                ->where('status', 'planned')
                ->whereDate('date', '<=', $now->toDateString())
                ->with(['group.members'])
                ->get()
                ->filter(fn (ClubGroupSession $s) => $this->ended($s, $now));

            foreach ($sessions as $session) {
                if (!$session->group) {
                    continue;
                }
                // Все активные участники считаются присутствовавшими; списание и
                // заморозку/пустой пакет разрулит сервис.
                $rows = [];
                foreach ($session->group->members->where('status', 'active') as $member) {
                    $rows[$member->id] = ['status' => 'charge'];
                }

                $service->conduct($session, $rows, null, $club);
                $conducted++;
            }
        }

        $this->info("Авто-проведено занятий: {$conducted}");
        return self::SUCCESS;
    }

    /** Занятие закончилось (дата+время окончания в прошлом), время клубное (Алматы). */
    private function ended(ClubGroupSession $session, Carbon $now): bool
    {
        $end = Carbon::parse(
            $session->date->format('Y-m-d') . ' ' . $session->end_time,
            'Asia/Almaty'
        );
        return $end->lessThanOrEqualTo($now);
    }
}
