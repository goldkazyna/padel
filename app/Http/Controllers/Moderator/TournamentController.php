<?php

namespace App\Http\Controllers\Moderator;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\User;
use App\Http\Controllers\Api\MobileTournamentController;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    private function getClub()
    {
        return auth()->user()->moderatorClubs()->first();
    }

    public function index()
    {
        $club = $this->getClub();
        
        if (!$club) {
            abort(403, 'Вы не привязаны к клубу');
        }

        // Только открытые турниры
        $tournaments = Tournament::where('club_id', $club->id)
            ->where('status', 'open')
            ->orderBy('start_date', 'asc')
            ->get();

        return view('moderator.tournaments.index', compact('tournaments', 'club'));
    }

    public function show(Tournament $tournament)
    {
        $club = $this->getClub();
        
        if (!$club || $tournament->club_id != $club->id) {
            abort(403);
        }

        if ($tournament->status !== 'open') {
            abort(403, 'Турнир недоступен для модерации');
        }

        $tournament->load(['club', 'participants']);
        
        return view('moderator.tournaments.show', compact('tournament'));
    }

    public function approveParticipant(Tournament $tournament, $userId)
    {
        $club = $this->getClub();
        
        if (!$club || $tournament->club_id != $club->id || $tournament->status !== 'open') {
            abort(403);
        }

        $tournament->participants()->updateExistingPivot($userId, ['status' => 'registered']);

        // Отправляем уведомления
        $user = \App\Models\User::find($userId);
        if ($user) {
            $telegramService = new \App\Services\TelegramNotificationService();
            $telegramService->notifyRegistrationApproved($user, $tournament);

            $date = $tournament->start_date->format('d.m.Y H:i');
            $title = 'Заявка одобрена!';
            $body = "Вы записаны на турнир «{$tournament->name}» — {$date}";

            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'body' => $body,
                'type' => 'registration_approved',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);

            $fcm = app(\App\Services\FCMNotificationService::class);
            $fcm->sendToUser($user, $title, $body, [
                'type' => 'registration_approved',
                'tournament_id' => (string) $tournament->id,
            ]);
        }

        return back()->with('success', 'Участник одобрен');
    }

    public function rejectParticipant(Tournament $tournament, $userId)
    {
        $club = $this->getClub();
        
        if (!$club || $tournament->club_id != $club->id || $tournament->status !== 'open') {
            abort(403);
        }

        // Получаем пользователя до удаления
        $user = \App\Models\User::find($userId);

        // Проверяем был ли турнир полным ДО удаления
        $takenSlots = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count();
        $wasFull = $takenSlots >= $tournament->max_participants;

        $tournament->participants()->detach($userId);

        // Отправляем уведомление об отклонении
        if ($user) {
            $telegramService = new \App\Services\TelegramNotificationService();
            $telegramService->notifyRegistrationRejected($user, $tournament);

            $title = 'Заявка отклонена';
            $body = "К сожалению, ваша заявка на турнир «{$tournament->name}» была отклонена";

            \App\Models\Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'body' => $body,
                'type' => 'registration_rejected',
                'category' => 'tournament',
                'data' => ['tournament_id' => $tournament->id],
            ]);

            $fcm = app(\App\Services\FCMNotificationService::class);
            $fcm->sendToUser($user, $title, $body, [
                'type' => 'registration_rejected',
                'tournament_id' => (string) $tournament->id,
            ]);
        }

        // Если турнир был полным — уведомляем в канал и подписчиков
        if ($wasFull && $tournament->status === 'open') {
            $channelService = new \App\Services\TelegramChannelService($tournament->club);
            if ($channelService->isConfigured()) {
                $channelService->postSlotAvailable($tournament);
            }
            MobileTournamentController::notifySubscribersSlotAvailable($tournament);
        }

        return back()->with('success', 'Заявка отклонена');
    }

    public function removeParticipant(Tournament $tournament, User $user)
    {
        $club = $this->getClub();

        if (!$club || $tournament->club_id != $club->id || $tournament->status !== 'open') {
            abort(403);
        }

        // Проверяем был ли турнир полным ДО удаления
        $takenSlots = $tournament->participants()
            ->wherePivotIn('status', ['registered', 'pending'])
            ->count();
        $wasFull = $takenSlots >= $tournament->max_participants;

        $tournament->participants()->detach($user->id);

        // Если турнир был полным — уведомляем в канал и подписчиков
        if ($wasFull && $tournament->status === 'open') {
            $channelService = new \App\Services\TelegramChannelService($tournament->club);
            if ($channelService->isConfigured()) {
                $channelService->postSlotAvailable($tournament);
            }
            MobileTournamentController::notifySubscribersSlotAvailable($tournament);
        }

        return back()->with('success', 'Участник удалён');
    }
}