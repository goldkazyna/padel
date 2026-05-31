<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use App\Services\ModerationNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessModerationTimers extends Command
{
    protected $signature = 'tournaments:process-moderation';
    protected $description = 'Снимает просроченные заявки на модерации и продвигает лист ожидания';

    public function handle(ModerationNotifier $notifier): int
    {
        $tournaments = Tournament::where('status', 'open')
            ->where(function ($q) {
                $q->where('moderation_hours', '>', 0)
                  ->orWhere('moderation_minutes', '>', 0);
            })
            ->get();

        foreach ($tournaments as $t) {
            $this->processSolo($t, $notifier);
            $this->processTeams($t, $notifier);
        }

        return self::SUCCESS;
    }

    private function processSolo(Tournament $t, ModerationNotifier $notifier): void
    {
        $now = now();
        $windowSeconds = $t->moderationWindowMinutes() * 60;
        $reminderLead = max($windowSeconds * 0.2, 1800); // 20% окна, минимум 30 мин

        $pending = $t->participants()
            ->wherePivot('status', 'pending')
            ->wherePivotNotNull('moderation_deadline')
            ->get();

        foreach ($pending as $p) {
            $deadline = Carbon::parse($p->pivot->moderation_deadline);
            $remaining = $deadline->getTimestamp() - $now->getTimestamp();

            if ($remaining <= 0) {
                DB::transaction(function () use ($t, $p, $notifier) {
                    $hasWaitlist = (int) ($t->waitlist_size ?? 0) > 0;

                    // первый в листе ожидания ДО демоции просрочившего
                    $nextWaiter = $t->participants()
                        ->wherePivot('status', 'waiting')
                        ->orderBy('tournament_participants.created_at')
                        ->first();

                    if ($hasWaitlist) {
                        $t->participants()->updateExistingPivot($p->id, [
                            'status' => 'waiting',
                            'moderation_deadline' => null,
                            'reminder_sent_at' => null,
                            'created_at' => now(), // в конец очереди
                        ]);
                        $notifier->demoted($p, $t);
                    } else {
                        $t->participants()->updateExistingPivot($p->id, [
                            'status' => 'cancelled',
                            'moderation_deadline' => null,
                            'reminder_sent_at' => null,
                        ]);
                        $notifier->expired($p, $t);
                    }

                    if ($nextWaiter) {
                        $deadline = $t->moderationDeadline();
                        $t->participants()->updateExistingPivot($nextWaiter->id, [
                            'status' => 'pending',
                            'moderation_deadline' => $deadline,
                            'reminder_sent_at' => null,
                        ]);
                        if ($deadline) {
                            $notifier->pending($nextWaiter, $t, $deadline);
                        }
                    }
                });
                continue;
            }

            if (!$p->pivot->reminder_sent_at && $remaining <= $reminderLead) {
                $t->participants()->updateExistingPivot($p->id, ['reminder_sent_at' => $now]);
                $notifier->reminder($p, $t, $deadline);
            }
        }
    }

    private function processTeams(Tournament $t, ModerationNotifier $notifier): void
    {
        if ($t->type !== 'team') return;

        $now = now();
        $windowSeconds = $t->moderationWindowMinutes() * 60;
        $reminderLead = max($windowSeconds * 0.2, 1800); // 20% окна, минимум 30 мин

        $pending = \App\Models\TournamentTeam::where('tournament_id', $t->id)
            ->where('status', 'pending')
            ->whereNotNull('moderation_deadline')
            ->get();

        foreach ($pending as $team) {
            $deadline = $team->moderation_deadline;
            $remaining = $deadline->getTimestamp() - $now->getTimestamp();

            if ($remaining <= 0) {
                DB::transaction(function () use ($t, $team, $notifier) {
                    $hasWaitlist = (int) ($t->waitlist_size ?? 0) > 0;

                    $nextTeam = \App\Models\TournamentTeam::where('tournament_id', $t->id)
                        ->where('status', 'waiting')
                        ->orderBy('created_at')
                        ->first();

                    if ($hasWaitlist) {
                        $team->status = 'waiting';
                        $team->moderation_deadline = null;
                        $team->reminder_sent_at = null;
                        $team->created_at = now(); // в конец очереди
                        $team->save();
                        if ($team->player1) $notifier->demoted($team->player1, $t);
                    } else {
                        $team->update(['status' => 'rejected', 'moderation_deadline' => null, 'reminder_sent_at' => null]);
                        if ($team->player1) $notifier->expired($team->player1, $t);
                    }

                    if ($nextTeam) {
                        $deadline = $t->moderationDeadline();
                        $nextTeam->update(['status' => 'pending', 'moderation_deadline' => $deadline, 'reminder_sent_at' => null]);
                        if ($deadline && $nextTeam->player1) {
                            $notifier->pending($nextTeam->player1, $t, $deadline);
                        }
                    }
                });
                continue;
            }

            if (!$team->reminder_sent_at && $remaining <= $reminderLead) {
                $team->update(['reminder_sent_at' => $now]);
                if ($team->player1) $notifier->reminder($team->player1, $t, $deadline);
            }
        }
    }

}
