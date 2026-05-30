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
            ->whereNotNull('moderation_hours')
            ->where('moderation_hours', '>', 0)
            ->get();

        foreach ($tournaments as $t) {
            $this->processSolo($t, $notifier);
        }

        return self::SUCCESS;
    }

    private function processSolo(Tournament $t, ModerationNotifier $notifier): void
    {
        $now = now();
        $windowSeconds = (int) $t->moderation_hours * 3600;
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
                    $t->participants()->updateExistingPivot($p->id, [
                        'status' => 'cancelled', 'moderation_deadline' => null, 'reminder_sent_at' => null,
                    ]);
                    $notifier->expired($p, $t);
                    $this->promoteFirstWaiting($t, $notifier);
                });
                continue;
            }

            if (!$p->pivot->reminder_sent_at && $remaining <= $reminderLead) {
                $t->participants()->updateExistingPivot($p->id, ['reminder_sent_at' => $now]);
                $notifier->reminder($p, $t, $deadline);
            }
        }
    }

    private function promoteFirstWaiting(Tournament $t, ModerationNotifier $notifier): void
    {
        $waiter = $t->participants()
            ->wherePivot('status', 'waiting')
            ->orderBy('tournament_participants.created_at')
            ->first();
        if (!$waiter) return;

        $deadline = $t->moderationDeadline();
        $t->participants()->updateExistingPivot($waiter->id, [
            'status' => 'pending',
            'moderation_deadline' => $deadline,
            'reminder_sent_at' => null,
        ]);
        if ($deadline) {
            $notifier->pending($waiter, $t, $deadline);
        }
    }
}
