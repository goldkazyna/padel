<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\TournamentTeam;

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
                    'participants_count' => $t->participants()->wherePivotIn('status', ['registered', 'pending'])->count(),
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
		
		$isRegistered = false;
		$registrationStatus = null;
		$canRegister = false;
		$blockReason = null;
		$userTeam = null;
		$participants = collect();
		$teams = collect();
		
		// Для командных турниров
		if ($tournament->type === 'team') {
			$teams = $tournament->teams()
				->with(['player1', 'player2'])
				->get()
				->map(fn($t) => [
					'id' => $t->id,
					'player1' => $t->player1->name,
					'player2' => $t->player2->name,
					'player1_level' => $t->player1->level,
					'player2_level' => $t->player2->level,
					'status' => $t->status,
				]);
			
			if ($user) {
				// Проверяем есть ли команда с этим пользователем
				$team = TournamentTeam::where('tournament_id', $tournament->id)
					->where(function($q) use ($user) {
						$q->where('player1_id', $user->id)
						  ->orWhere('player2_id', $user->id);
					})
					->with(['player1', 'player2'])
					->first();
				
				if ($team) {
					$isRegistered = true;
					$registrationStatus = $team->status;
					$userTeam = [
						'id' => $team->id,
						'player1' => $team->player1->name,
						'player2' => $team->player2->name,
						'status' => $team->status,
					];
				} else {
					// Проверяем может ли зарегистрироваться
					$maxTeams = $tournament->max_participants / 2;
					$approvedTeams = TournamentTeam::where('tournament_id', $tournament->id)
						->where('status', 'approved')
						->count();
					
					if ($user->level < $tournament->min_level) {
						$blockReason = "Ваш уровень ({$user->level}) ниже минимального ({$tournament->min_level})";
					} elseif ($user->level > $tournament->max_level) {
						$blockReason = "Ваш уровень ({$user->level}) выше максимального ({$tournament->max_level})";
					} elseif ($approvedTeams >= $maxTeams) {
						$blockReason = "Все места заняты";
					} else {
						$canRegister = true;
					}
				}
			}
			
			// Для team турнира считаем approved команды
			$participantsCount = TournamentTeam::where('tournament_id', $tournament->id)
				->whereIn('status', ['approved', 'pending'])
				->count() * 2;
				
		} else {
			// Для americano/mexicano — старая логика
			$participants = $tournament->participants()
				->wherePivotIn('status', ['registered', 'pending'])
				->get()
				->map(fn($p) => [
					'id' => $p->id,
					'name' => $p->name,
					'level' => $p->level,
					'rating' => $p->rating,
					'status' => $p->pivot->status,
				]);
			
			$participantsCount = $participants->count();
			
			if ($user) {
				$participant = $tournament->participants()->where('user_id', $user->id)->first();
				
				if ($participant) {
					$isRegistered = true;
					$registrationStatus = $participant->pivot->status;
				} else {
					$takenSlots = $tournament->participants()
						->wherePivotIn('status', ['registered', 'pending'])
						->count();
					
					if ($user->level < $tournament->min_level) {
						$blockReason = "Ваш уровень ({$user->level}) ниже минимального ({$tournament->min_level})";
					} elseif ($user->level > $tournament->max_level) {
						$blockReason = "Ваш уровень ({$user->level}) выше максимального ({$tournament->max_level})";
					} elseif ($takenSlots >= $tournament->max_participants) {
						$blockReason = "Все места заняты";
					} else {
						$canRegister = true;
					}
				}
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
				'participants_count' => $participantsCount,
				'max_participants' => $tournament->max_participants,
				'points_to_win' => $tournament->points_to_win,
				'rounds_count' => $tournament->rounds_count,
			],
			'participants' => $participants,
			'teams' => $teams,
			'user_team' => $userTeam,
			'is_registered' => $isRegistered,
			'registration_status' => $registrationStatus,
			'can_register' => $canRegister,
			'block_reason' => $blockReason,
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

		// Проверка мест (учитываем и registered, и pending)
		$takenSlots = $tournament->participants()
			->wherePivotIn('status', ['registered', 'pending'])
			->count();
		if ($takenSlots >= $tournament->max_participants) {
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

		// Проверяем был ли турнир полным ДО удаления
		$takenSlots = $tournament->participants()
			->wherePivotIn('status', ['registered', 'pending'])
			->count();
		$wasFull = $takenSlots >= $tournament->max_participants;

		$tournament->participants()->detach($user->id);

		// Если турнир был полным — уведомляем в канал
		if ($wasFull) {
			$channelService = new \App\Services\TelegramChannelService();
			$channelService->postSlotAvailable($tournament);
		}

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
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'rating' => $user->rating,
            'level' => $user->level,
            'phone' => $user->phone,
        ];
    }
	/**
	 * Сохранить телефон пользователя
	 */
	public function savePhone(Request $request)
	{
		$user = $this->getUser($request);

		if (!$user) {
			return response()->json(['error' => 'User not found'], 404);
		}

		$phone = $request->input('phone');
		
		if (empty($phone)) {
			return response()->json(['error' => 'Phone is required'], 400);
		}

		// Чистим телефон — оставляем только цифры
		$phone = preg_replace('/[^0-9]/', '', $phone);

		$user->update(['phone' => $phone]);

		return response()->json([
			'success' => true,
			'user' => $this->formatUser($user),
		]);
	}
	/**
	 * Добавь этот метод в TelegramMiniAppController.php
	 */

	/**
	 * Получить рейтинг игроков
	 */
	public function rating(Request $request)
	{
		$user = $this->getUser($request);
		$page = (int) $request->input('page', 1);
		$perPage = 20;
		
		// Общее количество игроков
		$total = User::where('role', 'player')->count();
		$totalPages = ceil($total / $perPage);
		
		// Получаем игроков для текущей страницы
		$players = User::where('role', 'player')
			->orderBy('rating', 'desc')
			->offset(($page - 1) * $perPage)
			->limit($perPage)
			->get()
			->map(function ($player, $index) use ($page, $perPage) {
				return [
					'id' => $player->id,
					'name' => $player->name ?? $player->first_name,
					'rating' => $player->rating,
					'level' => $player->level,
					'position' => ($page - 1) * $perPage + $index + 1,
				];
			});
		
		// Находим позицию текущего пользователя
		$myRank = null;
		$myPage = null;
		$myChange = 0;
		
		if ($user) {
			$myRank = User::where('role', 'player')
				->where('rating', '>', $user->rating)
				->count() + 1;
			
			$myPage = ceil($myRank / $perPage);
		}
		
		return response()->json([
			'players' => $players,
			'my_rank' => $myRank,
			'my_page' => $myPage,
			'my_change' => $myChange,
			'page' => $page,
			'total_pages' => $totalPages,
			'total' => $total,
		]);
	}


	/**
	 * Сохранить имя пользователя
	 */
	public function saveName(Request $request)
	{
		$user = $this->getUser($request);

		if (!$user) {
			return response()->json(['error' => 'User not found'], 404);
		}

		$name = $request->input('name');
		
		if (empty($name)) {
			return response()->json(['error' => 'Name is required'], 400);
		}

		$user->update(['name' => trim($name)]);

		return response()->json([
			'success' => true,
			'user' => $this->formatUser($user),
		]);
	}
	
	public function registrationStatus(Tournament $tournament, Request $request)
	{
		$user = $this->getUser($request);
		
		// Пишем в свой лог
		file_put_contents(
			storage_path('logs/polling.log'),
			date('Y-m-d H:i:s') . " | user: {$user?->id} | tournament: {$tournament->id}\n",
			FILE_APPEND
		);
		
		if (!$user) {
			return response()->json(['status' => null]);
		}
		
		$registration = $tournament->participants()
			->where('user_id', $user->id)
			->first();
		
		return response()->json([
			'status' => $registration?->pivot?->status,
		]);
	}
	/**
	 * Поиск партнера по телефону
	 */
	public function searchPartner(Request $request)
	{
		$user = $this->getUserFromRequest($request);
		if (!$user) {
			return response()->json(['error' => 'Unauthorized'], 401);
		}

		$phone = $request->input('phone');
		if (!$phone || strlen($phone) < 10) {
			return response()->json(['error' => 'Введите номер телефона'], 400);
		}

		// Убираем всё кроме цифр
		$phone = preg_replace('/\D/', '', $phone);

		// Ищем игрока
		$partner = User::where('role', 'player')
			->where('id', '!=', $user->id)
			->where(function($q) use ($phone) {
				$q->where('phone', 'LIKE', "%{$phone}%");
			})
			->first();

		if (!$partner) {
			return response()->json(['error' => 'Игрок не найден'], 404);
		}

		return response()->json([
			'found' => true,
			'partner' => [
				'id' => $partner->id,
				'name' => $partner->name,
				'level' => $partner->level,
				'phone' => $partner->phone ? '+' . preg_replace('/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/', '$1 $2 $3 $4 $5', $partner->phone) : null,
			]
		]);
	}

	/**
	 * Регистрация пары на турнир
	 */
	public function registerTeam(Request $request)
	{
		$user = $this->getUserFromRequest($request);
		if (!$user) {
			return response()->json(['error' => 'Unauthorized'], 401);
		}

		$tournamentId = $request->input('tournament_id');
		$partnerId = $request->input('partner_id');

		if (!$tournamentId || !$partnerId) {
			return response()->json(['error' => 'Не указан турнир или партнер'], 400);
		}

		$tournament = Tournament::find($tournamentId);
		if (!$tournament) {
			return response()->json(['error' => 'Турнир не найден'], 404);
		}

		if ($tournament->type !== 'team') {
			return response()->json(['error' => 'Это не командный турнир'], 400);
		}

		if ($tournament->status !== 'open') {
			return response()->json(['error' => 'Регистрация закрыта'], 400);
		}

		$partner = User::find($partnerId);
		if (!$partner) {
			return response()->json(['error' => 'Партнер не найден'], 404);
		}

		// Проверяем уровни
		if ($user->level < $tournament->min_level || $user->level > $tournament->max_level) {
			return response()->json(['error' => "Ваш уровень ({$user->level}) не подходит для турнира"], 400);
		}

		if ($partner->level < $tournament->min_level || $partner->level > $tournament->max_level) {
			return response()->json(['error' => "Уровень партнера ({$partner->level}) не подходит для турнира"], 400);
		}

		// Проверяем, не зарегистрированы ли уже
		$existingTeam = TournamentTeam::where('tournament_id', $tournament->id)
			->where(function($q) use ($user, $partner) {
				$q->where(function($q2) use ($user, $partner) {
					$q2->where('player1_id', $user->id)
					   ->orWhere('player2_id', $user->id);
				})->orWhere(function($q2) use ($partner) {
					$q2->where('player1_id', $partner->id)
					   ->orWhere('player2_id', $partner->id);
				});
			})
			->first();

		if ($existingTeam) {
			return response()->json(['error' => 'Вы или ваш партнер уже зарегистрированы'], 400);
		}

		// Проверяем количество мест (только approved команды)
		$maxTeams = $tournament->max_participants / 2;
		$approvedTeams = TournamentTeam::where('tournament_id', $tournament->id)
			->where('status', 'approved')
			->count();
		$pendingTeams = TournamentTeam::where('tournament_id', $tournament->id)
			->where('status', 'pending')
			->count();

		if ($approvedTeams >= $maxTeams) {
			return response()->json(['error' => 'Все места заняты'], 400);
		}

		// Создаём команду со статусом pending
		$team = TournamentTeam::create([
			'tournament_id' => $tournament->id,
			'player1_id' => $user->id,
			'player2_id' => $partner->id,
			'rating_avg' => ($user->rating + $partner->rating) / 2,
			'status' => 'pending',
		]);

		return response()->json([
			'success' => true,
			'message' => 'Заявка отправлена на модерацию',
			'team' => [
				'id' => $team->id,
				'player1' => $user->name,
				'player2' => $partner->name,
				'status' => 'pending',
			]
		]);
	}

	/**
	 * Отмена регистрации пары
	 */
	public function cancelTeam(Request $request)
	{
		$user = $this->getUserFromRequest($request);
		if (!$user) {
			return response()->json(['error' => 'Unauthorized'], 401);
		}

		$tournamentId = $request->input('tournament_id');

		$tournament = Tournament::find($tournamentId);
		if (!$tournament) {
			return response()->json(['error' => 'Турнир не найден'], 404);
		}

		if ($tournament->status !== 'open') {
			return response()->json(['error' => 'Отмена невозможна'], 400);
		}

		// Ищем команду где пользователь участвует
		$team = TournamentTeam::where('tournament_id', $tournament->id)
			->where(function($q) use ($user) {
				$q->where('player1_id', $user->id)
				  ->orWhere('player2_id', $user->id);
			})
			->first();

		if (!$team) {
			return response()->json(['error' => 'Вы не зарегистрированы'], 404);
		}

		$team->delete();

		return response()->json([
			'success' => true,
			'message' => 'Регистрация отменена'
		]);
	}

}