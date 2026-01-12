<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TelegramMiniAppController extends Controller
{
    /**
     * Авторизация / регистрация через Telegram
     */
    public function auth(Request $request)
    {
        $telegramUser = $request->telegram_user['user'] ?? null;
        
        if (!$telegramUser || empty($telegramUser['id'])) {
            return response()->json(['error' => 'Invalid user data'], 400);
        }

        $telegramId = (string) $telegramUser['id'];
        
        // Ищем пользователя
        $user = User::where('telegram_id', $telegramId)->first();

        if (!$user) {
            // Создаём нового пользователя
            $firstName = $telegramUser['first_name'] ?? 'Игрок';
            $lastName = $telegramUser['last_name'] ?? '';
            
            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName ?: $telegramId,
                'name' => trim("$firstName $lastName") ?: "Игрок $telegramId",
                'email' => "tg_{$telegramId}@padel.local",
                'phone' => null,
                'telegram_id' => $telegramId,
                'password' => Hash::make('tg_' . $telegramId . '_' . time()),
                'role' => 'player',
                'rating' => 1000,
                'level' => 1.0,
            ]);
        } else {
            // Обновляем имя если изменилось
            $user->update([
                'first_name' => $telegramUser['first_name'] ?? $user->first_name,
                'last_name' => $telegramUser['last_name'] ?? $user->last_name,
            ]);
        }

        return response()->json([
            'success' => true,
            'user' => $this->formatUser($user),
            'is_new' => $user->wasRecentlyCreated,
        ]);
    }

    /**
     * Профиль пользователя
     */
    public function profile(Request $request)
    {
        $user = $this->getUser($request);
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $stats = $user->getAllMatchesStats();
        $ratingHistory = $user->ratingHistory()
            ->take(10)
            ->get()
            ->map(fn($h) => [
                'date' => $h->created_at->format('d.m'),
                'change' => $h->change,
                'rating' => $h->rating_after,
                'tournament' => $h->reason,
            ]);

        // Позиция в рейтинге
        $rank = User::where('role', 'player')
            ->where('rating', '>', $user->rating)
            ->count() + 1;

        return response()->json([
            'user' => $this->formatUser($user),
            'stats' => $stats,
            'rating_history' => $ratingHistory,
            'rank' => $rank,
        ]);
    }

    /**
     * Список открытых турниров
     */
    public function tournaments(Request $request)
    {
        $user = $this->getUser($request);

        $tournaments = Tournament::where('status', 'open')
            ->where('start_date', '>', now())
            ->orderBy('start_date', 'asc')
            ->with('club')
            ->get()
            ->map(function ($t) use ($user) {
                $isRegistered = false;
                $registrationStatus = null;
                
                if ($user) {
                    $participant = $t->participants()->where('user_id', $user->id)->first();
                    if ($participant) {
                        $isRegistered = true;
                        $registrationStatus = $participant->pivot->status;
                    }
                }

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'club' => $t->club->name ?? 'Клуб',
                    'date' => $t->start_date->format('d.m.Y'),
                    'time' => $t->start_date->format('H:i'),
                    'type' => $t->type,
                    'type_name' => $t->type_name,
                    'min_level' => $t->min_level,
                    'max_level' => $t->max_level,
                    'price' => $t->price,
                    'participants_count' => $t->participants()->wherePivot('status', 'registered')->count(),
                    'max_participants' => $t->max_participants,
                    'is_registered' => $isRegistered,
                    'registration_status' => $registrationStatus,
                ];
            });

        return response()->json(['tournaments' => $tournaments]);
    }

    /**
     * Детали турнира
     */
    public function tournamentShow(Request $request, Tournament $tournament)
    {
        $user = $this->getUser($request);

        $participants = $tournament->participants()
            ->wherePivot('status', 'registered')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->full_name,
                'level' => $p->level,
                'rating' => $p->rating,
            ]);

        $isRegistered = false;
        $registrationStatus = null;
        $canRegister = false;

        if ($user) {
            $participant = $tournament->participants()->where('user_id', $user->id)->first();
            if ($participant) {
                $isRegistered = true;
                $registrationStatus = $participant->pivot->status;
            } else {
                // Проверяем может ли зарегистрироваться
                $canRegister = $user->level >= $tournament->min_level 
                    && $user->level <= $tournament->max_level
                    && $tournament->participants()->wherePivot('status', 'registered')->count() < $tournament->max_participants;
            }
        }

        return response()->json([
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'description' => $tournament->description,
                'club' => $tournament->club->name ?? 'Клуб',
                'address' => $tournament->club->address ?? '',
                'date' => $tournament->start_date->format('d.m.Y'),
                'time' => $tournament->start_date->format('H:i'),
                'type' => $tournament->type,
                'type_name' => $tournament->type_name,
                'min_level' => $tournament->min_level,
                'max_level' => $tournament->max_level,
                'price' => $tournament->price,
                'participants_count' => $participants->count(),
                'max_participants' => $tournament->max_participants,
                'points_to_win' => $tournament->points_to_win,
                'rounds_count' => $tournament->rounds_count,
            ],
            'participants' => $participants,
            'is_registered' => $isRegistered,
            'registration_status' => $registrationStatus,
            'can_register' => $canRegister,
        ]);
    }

    /**
     * Регистрация на турнир
     */
    public function register(Request $request, Tournament $tournament)
    {
        $user = $this->getUser($request);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Проверки
        if ($tournament->status !== 'open') {
            return response()->json(['error' => 'Турнир не открыт для регистрации'], 400);
        }

        // Уже зарегистрирован?
        if ($tournament->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Вы уже зарегистрированы'], 400);
        }

        // Проверка уровня
        if ($user->level < $tournament->min_level || $user->level > $tournament->max_level) {
            return response()->json([
                'error' => "Ваш уровень ({$user->level}) не подходит. Требуется: {$tournament->min_level} - {$tournament->max_level}"
            ], 400);
        }

        // Проверка мест
        $registeredCount = $tournament->participants()->wherePivot('status', 'registered')->count();
        if ($registeredCount >= $tournament->max_participants) {
            return response()->json(['error' => 'Все места заняты'], 400);
        }

        // Регистрируем (статус pending для модерации или сразу registered)
        $status = 'pending'; // Или 'registered' если без модерации
        
        $tournament->participants()->attach($user->id, ['status' => $status]);

        return response()->json([
            'success' => true,
            'message' => $status === 'pending' 
                ? 'Заявка отправлена на модерацию' 
                : 'Вы зарегистрированы!',
            'status' => $status,
        ]);
    }

    /**
     * Отмена регистрации
     */
    public function cancelRegistration(Request $request, Tournament $tournament)
    {
        $user = $this->getUser($request);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $participant = $tournament->participants()->where('user_id', $user->id)->first();
        
        if (!$participant) {
            return response()->json(['error' => 'Вы не зарегистрированы'], 400);
        }

        // Нельзя отменить если турнир уже начался
        if ($tournament->status !== 'open') {
            return response()->json(['error' => 'Турнир уже начался'], 400);
        }

        $tournament->participants()->detach($user->id);

        return response()->json([
            'success' => true,
            'message' => 'Регистрация отменена',
        ]);
    }

    /**
     * Получить пользователя из Telegram данных
     */
    private function getUser(Request $request): ?User
    {
        $telegramUser = $request->telegram_user['user'] ?? null;
        
        if (!$telegramUser) {
            return null;
        }

        return User::where('telegram_id', (string) $telegramUser['id'])->first();
    }

    /**
     * Форматирование пользователя
     */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'rating' => $user->rating,
            'level' => $user->level,
            'phone' => $user->phone,
        ];
    }
}