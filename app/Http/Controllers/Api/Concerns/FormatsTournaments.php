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
    use ResolvesTournamentChatAccess;

    private function formatTournament(Tournament $t, $user, bool $includeRegistration = false): array
    {
        $club = $t->club; // null для личных турниров
        $creator = $t->creator; // задан для личных турниров
        $data = [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'has_prizes' => (bool) $t->has_prizes,
            'prizes' => $t->prizes,
            'jpi_rank_by_wins' => (bool) $t->jpi_rank_by_wins,
            'telegram_registration_url' => $t->telegram_registration_url,
            // Для личного турнира клуба нет — в качестве «организатора»
            // показываем имя создателя.
            'club' => [
                // 0 для личного турнира (клуба нет) — иначе старые сборки падают
                // на Club.fromJson (id обязателен).
                'id' => $club?->id ?? 0,
                'name' => $club?->name ?? ($creator?->name ?? 'Личный турнир'),
                'phone' => $club?->phone,
                'address' => $club?->address,
                'city' => $club?->city,
                'payment_url' => $club?->payment_url,
                'telegram_url' => $club?->telegram_url,
                'logo' => $club?->logo ? url($club->logo) : null,
                'is_community' => (bool) ($club?->is_community ?? false),
                'created_at' => $club?->created_at?->toIso8601String(),
            ],
            'is_personal' => $t->isPersonal(),
            'creator' => $creator ? ['id' => $creator->id, 'name' => $creator->name] : null,
            'date' => $t->start_date->format('d.m.Y'),
            // В деталях, если задана длительность — показываем диапазон 11:00 – 14:00.
            'time' => ($includeRegistration && $t->duration_hours)
                ? $t->start_date->format('H:i') . ' – ' . $t->start_date->copy()->addHours($t->duration_hours)->format('H:i')
                : $t->start_date->format('H:i'),
            'duration_hours' => $t->duration_hours,
            'datetime' => $t->start_date->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'type' => $t->type,
            'type_name' => $t->type_name,
            // Клуб-площадка (где играют) — null, если не задан.
            'venue_club' => $t->venueClub ? [
                'id' => $t->venueClub->id,
                'name' => $t->venueClub->name,
                'city' => $t->venueClub->city,
            ] : null,
            'status' => $t->status,
            'status_name' => $t->status_name,
            // Этап лиги: приложение показывает плашку «Этап 3 из 8» со
            // ссылкой на лигу. У обычного турнира — null.
            'league' => $t->league ? [
                'id' => $t->league->id,
                'name' => $t->league->name,
                'stage' => (int) $t->league_stage,
                'stages_total' => max((int) $t->league->stages_planned, $t->league->stages()->count()),
            ] : null,
            'is_rated' => (bool) $t->is_rated,
            'verified_only' => (bool) $t->verified_only,
            'pairing_mode' => $t->pairing_mode ?? 'self',
            'is_admin_pairing' => $t->isAdminPairing(),
            // Готовый ответ на вопрос «поодиночке или парой»: клиенту не нужно
            // знать, у каких форматов бывают пары.
            'uses_solo_registration' => $t->usesSoloRegistration(),
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

    /**
     * Сколько мест занято.
     *
     * Правило одно на всё приложение и живёт в модели: где записываются
     * парой — считаем пары, где поодиночке — участников. Раньше здесь было
     * четыре копии этого метода, и две отстали: главная показывала 0/12
     * у турнира с фиксированными парами, пока деталка показывала 10/12.
     */
    private function getParticipantsCount(Tournament $t): int
    {
        return $t->takenSlotsCount();
    }
}
