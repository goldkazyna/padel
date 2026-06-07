<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Tournament;
use App\Models\TournamentTeam;

/**
 * Форматирование турнира для мобильного списка (карточка турнира).
 * Используется в MobileTournamentController и MobileTournamentInvitationController,
 * чтобы карточка приглашения выглядела так же, как во вкладке турниров.
 */
trait FormatsTournaments
{
    private function formatTournament(Tournament $t, $user, bool $includeRegistration = false): array
    {
        $data = [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'telegram_registration_url' => $t->telegram_registration_url,
            'club' => [
                'id' => $t->club->id ?? null,
                'name' => $t->club->name ?? 'Клуб',
                'phone' => $t->club->phone ?? null,
                'address' => $t->club->address ?? null,
                'payment_url' => $t->club->payment_url ?? null,
                'telegram_url' => $t->club->telegram_url ?? null,
                'logo' => $t->club->logo ? url($t->club->logo) : null,
                'is_community' => (bool) ($t->club->is_community ?? false),
            ],
            'date' => $t->start_date->format('d.m.Y'),
            'time' => $t->start_date->format('H:i'),
            'datetime' => $t->start_date->toIso8601String(),
            'type' => $t->type,
            'type_name' => $t->type_name,
            'status' => $t->status,
            'status_name' => $t->status_name,
            'is_rated' => (bool) $t->is_rated,
            'pairing_mode' => $t->pairing_mode ?? 'self',
            'is_admin_pairing' => $t->isAdminPairing(),
            'min_level' => (float) $t->min_level,
            'max_level' => (float) $t->max_level,
            'price' => (float) $t->price,
            'max_participants' => $t->max_participants,
            'participants_count' => $this->getParticipantsCount($t),
            'spots_left' => max(0, $t->max_participants - $this->getParticipantsCount($t)),
            'waitlist_size' => (int) ($t->waitlist_size ?? 0),
            'waitlist_count' => $t->waitlistCount(),
            'waitlist_available' => $t->hasWaitlistSlot(),
        ];

        if ($user && $includeRegistration) {
            $registration = $this->getUserRegistration($t, $user);
            $data['is_registered'] = $registration['is_registered'];
            $data['registration_status'] = $registration['status'];
            $data['can_register'] = $registration['can_register'];
            $data['block_reason'] = $registration['block_reason'];
            $data['in_waitlist'] = $registration['in_waitlist'];
            $data['waitlist_position'] = $registration['waitlist_position'];
            $data['moderation_deadline'] = $registration['moderation_deadline'] ?? null;
        }

        return $data;
    }

    private function getParticipantsCount(Tournament $t): int
    {
        if (!$t->usesSoloRegistration()) {
            return TournamentTeam::where('tournament_id', $t->id)
                ->whereIn('status', ['approved', 'pending'])
                ->count() * 2;
        }

        return $t->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count();
    }
}
