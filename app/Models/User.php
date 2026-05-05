<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\GameMatch;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'patronymic',
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'birth_date',
        'gender',
        'age',
        'hand',
        'position',
        'city',
        'role',
        'rating',
        'level',
        'level_verified',
        'quiz_completed',
        'quiz_answers',
        'telegram_id',
        'google_id',
        'apple_id',
        'last_played_at',
        'notify_only_my_level',
        'notify_club_ids',
        'hidden_club_ids',
        'terms_accepted_at',
        'terms_version',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'birth_date' => 'date',
        'last_played_at' => 'datetime',
        'level' => 'decimal:2',
        'level_verified' => 'boolean',
        'quiz_completed' => 'boolean',
        'quiz_answers' => 'array',
        'notify_only_my_level' => 'boolean',
        'notify_club_ids' => 'array',
        'hidden_club_ids' => 'array',
        'terms_accepted_at' => 'datetime',
    ];

    // Связь: клубы где юзер админ
    public function adminClubs()
    {
        return $this->belongsToMany(Club::class, 'club_admins');
    }
	// Связь: клубы где юзер модератор
	public function moderatorClubs()
	{
		return $this->belongsToMany(Club::class, 'club_moderators')
			->withPivot('tournaments_full_access');
	}

	/**
	 * Полный доступ к турнирам клуба (создание/правка/удаление).
	 * Есть у:
	 *  - super_admin
	 *  - club_admin данного клуба
	 *  - club_moderator данного клуба с флагом tournaments_full_access
	 */
	public function hasTournamentsFullAccess(Club $club): bool
	{
		if ($this->isSuperAdmin()) return true;
		if ($this->isClubAdmin()
			&& $this->adminClubs()->where('clubs.id', $club->id)->exists()) {
			return true;
		}
		if ($this->isClubModerator()) {
			$mod = $this->moderatorClubs()
				->where('clubs.id', $club->id)
				->first();
			return $mod !== null
				&& (bool) $mod->pivot->tournaments_full_access;
		}
		return false;
	}
    // Проверки ролей
    public function isPlayer(): bool
    {
        return $this->role === 'player';
    }

    public function isClubAdmin(): bool
    {
        return $this->role === 'club_admin';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }
	public function isClubModerator(): bool
	{
		return $this->role === 'club_moderator';
	}

    public function isCoach(): bool
    {
        return $this->role === 'coach';
    }

    public function coachClubs()
    {
        return $this->belongsToMany(Club::class, 'club_coaches');
    }

    public function coachProfile()
    {
        return $this->hasMany(ClubCoach::class);
    }
    // Полное имя
    public function getFullNameAttribute(): string
    {
        return $this->name ?: ($this->first_name . ' ' . $this->last_name);
    }

    // Уровень текстом
    public function getLevelNameAttribute(): string
    {
        if ($this->level < 2) return 'Начинающий';
        if ($this->level < 3) return 'Любитель';
        if ($this->level < 4) return 'Средний';
        if ($this->level < 5) return 'Продвинутый';
        return 'Про';
    }
	// Турниры в которых участвует
	public function tournaments()
	{
		return $this->belongsToMany(Tournament::class, 'tournament_participants')
					->withPivot('status')
					->withTimestamps();
	}
	
	// Матчи игрока
	public function matches()
	{
		return GameMatch::where('player1_id', $this->id)
					->orWhere('player2_id', $this->id)
					->orderBy('created_at', 'desc');
	}

		


	public function tournamentGroups()
	{
		return $this->belongsToMany(TournamentGroup::class, 'tournament_group_players')
					->withPivot('total_points')
					->withTimestamps();
	}
	public function mexicanoPlayers()
	{
		return $this->hasMany(MexicanoPlayer::class);
	}

	/**
	 * Получить статистику всех матчей (все типы турниров)
	 */
	public function getAllMatchesStats(): array
	{
		$stats = [
			'total' => 0,
			'won' => 0,
			'lost' => 0,
			'draw' => 0,
		];
		
		// Американо - групповые матчи
		$americanoMatches = \App\Models\AmericanoMatch::where('status', 'completed')
			->where(function($q) {
				$q->where('team1_player1_id', $this->id)
				  ->orWhere('team1_player2_id', $this->id)
				  ->orWhere('team2_player1_id', $this->id)
				  ->orWhere('team2_player2_id', $this->id);
			})->get();
		
		foreach ($americanoMatches as $match) {
			$stats['total']++;
			$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;
			
			if ($match->team1_score == $match->team2_score) {
				$stats['draw']++;
			} elseif ($match->team1_score > $match->team2_score) {
				$isTeam1 ? $stats['won']++ : $stats['lost']++;
			} else {
				$isTeam1 ? $stats['lost']++ : $stats['won']++;
			}
		}
		
		// Мексикано - групповые матчи
		$mexicanoMatches = \App\Models\MexicanoMatch::where('status', 'completed')
			->where(function($q) {
				$q->where('team1_player1_id', $this->id)
				  ->orWhere('team1_player2_id', $this->id)
				  ->orWhere('team2_player1_id', $this->id)
				  ->orWhere('team2_player2_id', $this->id);
			})->get();
		
		foreach ($mexicanoMatches as $match) {
			$stats['total']++;
			$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;
			
			if ($match->team1_score == $match->team2_score) {
				$stats['draw']++;
			} elseif ($match->team1_score > $match->team2_score) {
				$isTeam1 ? $stats['won']++ : $stats['lost']++;
			} else {
				$isTeam1 ? $stats['lost']++ : $stats['won']++;
			}
		}
		
		// Король корта — все матчи всех раундов
		$kocMatches = \App\Models\KingOfCourtMatch::where('status', 'completed')
			->where(function($q) {
				$q->where('team1_player1_id', $this->id)
				  ->orWhere('team1_player2_id', $this->id)
				  ->orWhere('team2_player1_id', $this->id)
				  ->orWhere('team2_player2_id', $this->id);
			})->get();

		foreach ($kocMatches as $match) {
			$stats['total']++;
			$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;

			if ($match->team1_score == $match->team2_score) {
				$stats['draw']++;
			} elseif ($match->team1_score > $match->team2_score) {
				$isTeam1 ? $stats['won']++ : $stats['lost']++;
			} else {
				$isTeam1 ? $stats['lost']++ : $stats['won']++;
			}
		}

		// Плей-офф матчи Американо/Мексикано (по player_id)
		$playoffPlayerMatches = \App\Models\TournamentPlayoffMatch::where('status', 'completed')
			->whereNotNull('team1_player1_id') // Это Американо/Мексикано матч
			->where(function($q) {
				$q->where('team1_player1_id', $this->id)
				  ->orWhere('team1_player2_id', $this->id)
				  ->orWhere('team2_player1_id', $this->id)
				  ->orWhere('team2_player2_id', $this->id);
			})->get();
		
		foreach ($playoffPlayerMatches as $match) {
			$stats['total']++;
			$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;
			
			if ($match->team1_score == $match->team2_score) {
				$stats['draw']++;
			} elseif ($match->team1_score > $match->team2_score) {
				$isTeam1 ? $stats['won']++ : $stats['lost']++;
			} else {
				$isTeam1 ? $stats['lost']++ : $stats['won']++;
			}
		}
		
		// Командный турнир
		$teamIds = \App\Models\TournamentTeam::where('player1_id', $this->id)
			->orWhere('player2_id', $this->id)
			->pluck('id');
		
		if ($teamIds->count() > 0) {
			// Групповой этап
			$groupMatches = \App\Models\TournamentGroupMatch::where('status', 'completed')
				->where(function($q) use ($teamIds) {
					$q->whereIn('team1_id', $teamIds)
					  ->orWhereIn('team2_id', $teamIds);
				})->get();
			
			foreach ($groupMatches as $match) {
				$stats['total']++;
				$isTeam1 = $teamIds->contains($match->team1_id);
				
				if ($match->team1_score == $match->team2_score) {
					$stats['draw']++;
				} elseif ($match->team1_score > $match->team2_score) {
					$isTeam1 ? $stats['won']++ : $stats['lost']++;
				} else {
					$isTeam1 ? $stats['lost']++ : $stats['won']++;
				}
			}
			
			// Плей-офф командного турнира (по team_id)
			$playoffTeamMatches = \App\Models\TournamentPlayoffMatch::where('status', 'completed')
				->whereNull('team1_player1_id') // Это командный матч
				->where(function($q) use ($teamIds) {
					$q->whereIn('team1_id', $teamIds)
					  ->orWhereIn('team2_id', $teamIds);
				})->get();
			
			foreach ($playoffTeamMatches as $match) {
				$stats['total']++;
				$isTeam1 = $teamIds->contains($match->team1_id);
				
				if ($match->team1_score == $match->team2_score) {
					$stats['draw']++;
				} elseif ($match->team1_score > $match->team2_score) {
					$isTeam1 ? $stats['won']++ : $stats['lost']++;
				} else {
					$isTeam1 ? $stats['lost']++ : $stats['won']++;
				}
			}
		}
		
		return $stats;
	}

	/**
	 * Победы (все турниры)
	 */
	public function wins(): int
	{
		return $this->getAllMatchesStats()['won'];
	}

	/**
	 * Поражения (все турниры)
	 */
	public function losses(): int
	{
		return $this->getAllMatchesStats()['lost'];
	}

	/**
	 * Винрейт (все турниры)
	 */
	public function winRate(): float
	{
		$stats = $this->getAllMatchesStats();
		if ($stats['total'] === 0) return 0;
		return round(($stats['won'] / $stats['total']) * 100, 1);
	}
	/**
	 * Получить историю всех матчей
	 */
	public function getMatchHistory(): array
	{
		$matches = [];

		// Американо
		$americanoMatches = \App\Models\AmericanoMatch::where('status', 'completed')
			->where(function($q) {
				$q->where('team1_player1_id', $this->id)
				  ->orWhere('team1_player2_id', $this->id)
				  ->orWhere('team2_player1_id', $this->id)
				  ->orWhere('team2_player2_id', $this->id);
			})
			->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.group.tournament'])
			->get();

		foreach ($americanoMatches as $match) {
			$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;
			$won = ($isTeam1 && $match->team1_score > $match->team2_score) || (!$isTeam1 && $match->team2_score > $match->team1_score);
			
			$partner = $isTeam1 
				? ($match->team1_player1_id == $this->id ? $match->team1Player2 : $match->team1Player1)
				: ($match->team2_player1_id == $this->id ? $match->team2Player2 : $match->team2Player1);
			
			$opponents = $isTeam1 
				? [$match->team2Player1, $match->team2Player2]
				: [$match->team1Player1, $match->team1Player2];

			$matches[] = [
				'type' => 'Американо',
				'tournament' => $match->round->group->tournament->name ?? 'Турнир',
				'date' => $match->updated_at,
				'partner' => $partner->full_name ?? '',
				'opponents' => ($opponents[0]->full_name ?? '') . ' / ' . ($opponents[1]->full_name ?? ''),
				'score' => $isTeam1 ? "{$match->team1_score}:{$match->team2_score}" : "{$match->team2_score}:{$match->team1_score}",
				'won' => $won,
			];
		}

		// Мексикано
		$mexicanoMatches = \App\Models\MexicanoMatch::where('status', 'completed')
			->where(function($q) {
				$q->where('team1_player1_id', $this->id)
				  ->orWhere('team1_player2_id', $this->id)
				  ->orWhere('team2_player1_id', $this->id)
				  ->orWhere('team2_player2_id', $this->id);
			})
			->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2', 'round.tournament'])
			->get();

		foreach ($mexicanoMatches as $match) {
			$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;
			$won = ($isTeam1 && $match->team1_score > $match->team2_score) || (!$isTeam1 && $match->team2_score > $match->team1_score);
			
			$partner = $isTeam1 
				? ($match->team1_player1_id == $this->id ? $match->team1Player2 : $match->team1Player1)
				: ($match->team2_player1_id == $this->id ? $match->team2Player2 : $match->team2Player1);
			
			$opponents = $isTeam1 
				? [$match->team2Player1, $match->team2Player2]
				: [$match->team1Player1, $match->team1Player2];

			$matches[] = [
				'type' => 'Мексикано',
				'tournament' => $match->round->tournament->name ?? 'Турнир',
				'date' => $match->updated_at,
				'partner' => $partner->full_name ?? '',
				'opponents' => ($opponents[0]->full_name ?? '') . ' / ' . ($opponents[1]->full_name ?? ''),
				'score' => $isTeam1 ? "{$match->team1_score}:{$match->team2_score}" : "{$match->team2_score}:{$match->team1_score}",
				'won' => $won,
			];
		}

		// Групповой турнир
		$teamIds = \App\Models\TournamentTeam::where('player1_id', $this->id)
			->orWhere('player2_id', $this->id)
			->pluck('id');

		if ($teamIds->count() > 0) {
			$teams = \App\Models\TournamentTeam::whereIn('id', $teamIds)
				->with(['player1', 'player2', 'tournament'])
				->get()
				->keyBy('id');

			// Групповой этап
			$groupMatches = \App\Models\TournamentGroupMatch::where('status', 'completed')
				->where(function($q) use ($teamIds) {
					$q->whereIn('team1_id', $teamIds)
					  ->orWhereIn('team2_id', $teamIds);
				})
				->with(['team1.player1', 'team1.player2', 'team2.player1', 'team2.player2', 'group.tournament'])
				->get();

			foreach ($groupMatches as $match) {
				$isTeam1 = $teamIds->contains($match->team1_id);
				$won = ($isTeam1 && $match->team1_score > $match->team2_score) || (!$isTeam1 && $match->team2_score > $match->team1_score);
				
				$myTeam = $isTeam1 ? $match->team1 : $match->team2;
				$oppTeam = $isTeam1 ? $match->team2 : $match->team1;
				
				$partner = $myTeam->player1_id == $this->id ? $myTeam->player2 : $myTeam->player1;

				$matches[] = [
					'type' => 'Групповой',
					'tournament' => $match->group->tournament->name ?? 'Турнир',
					'date' => $match->updated_at,
					'partner' => $partner->full_name ?? '',
					'opponents' => ($oppTeam->player1->full_name ?? '') . ' / ' . ($oppTeam->player2->full_name ?? ''),
					'score' => $isTeam1 ? "{$match->team1_score}:{$match->team2_score}" : "{$match->team2_score}:{$match->team1_score}",
					'won' => $won,
				];
			}

			// Плей-офф
			$playoffMatches = \App\Models\TournamentPlayoffMatch::where('status', 'completed')
				->where(function($q) use ($teamIds) {
					$q->whereIn('team1_id', $teamIds)
					  ->orWhereIn('team2_id', $teamIds);
				})
				->with(['team1.player1', 'team1.player2', 'team2.player1', 'team2.player2', 'tournament'])
				->get();

			foreach ($playoffMatches as $match) {
				$isTeam1 = $teamIds->contains($match->team1_id);
				$won = ($isTeam1 && $match->team1_score > $match->team2_score) || (!$isTeam1 && $match->team2_score > $match->team1_score);
				
				$myTeam = $isTeam1 ? $match->team1 : $match->team2;
				$oppTeam = $isTeam1 ? $match->team2 : $match->team1;
				
				$partner = $myTeam->player1_id == $this->id ? $myTeam->player2 : $myTeam->player1;

				$matches[] = [
					'type' => 'Плей-офф',
					'tournament' => $match->tournament->name ?? 'Турнир',
					'date' => $match->updated_at,
					'partner' => $partner->full_name ?? '',
					'opponents' => ($oppTeam->player1->full_name ?? '') . ' / ' . ($oppTeam->player2->full_name ?? ''),
					'score' => $isTeam1 ? "{$match->team1_score}:{$match->team2_score}" : "{$match->team2_score}:{$match->team1_score}",
					'won' => $won,
				];
			}
		}

		// Сортируем по дате (новые первыми)
		usort($matches, fn($a, $b) => $b['date'] <=> $a['date']);

		return $matches;
	}
	
	public function courtBookings()
	{
		return $this->hasMany(CourtBooking::class);
	}

	public function deviceTokens()
	{
		return $this->hasMany(DeviceToken::class);
	}

	public function notifications()
	{
		return $this->hasMany(Notification::class);
	}

	public function ratingHistory()
	{
		return $this->hasMany(RatingHistory::class)->orderBy('created_at', 'desc');
	}
	/**
	 * Статистика по турнирам
	 */
	public function getTournamentStats(): array
	{
		$stats = [
			'total' => 0,
			'wins' => 0,
			'by_type' => [],
		];

		// Американо
		$americanoCount = \App\Models\Tournament::where('type', 'americano')
			->where('status', 'completed')
			->whereHas('groups.players', function($q) {
				$q->where('users.id', $this->id);
			})->count();
		
		if ($americanoCount > 0) {
			$stats['total'] += $americanoCount;
			$stats['by_type']['americano'] = $americanoCount;
		}

		// Мексикано
		$mexicanoTournaments = \App\Models\Tournament::where('type', 'mexicano')
			->where('status', 'completed')
			->whereHas('mexicanoPlayers', function($q) {
				$q->where('user_id', $this->id);
			})->get();

		foreach ($mexicanoTournaments as $tournament) {
			$stats['total']++;
			$stats['by_type']['mexicano'] = ($stats['by_type']['mexicano'] ?? 0) + 1;
			
			// Проверяем 1-е место
			$winner = $tournament->mexicanoPlayers()->orderBy('total_points', 'desc')->first();
			if ($winner && $winner->user_id === $this->id) {
				$stats['wins']++;
			}
		}

		// Король корта
		$kocTournaments = \App\Models\Tournament::where('type', 'king_of_court')
			->where('status', 'completed')
			->whereHas('kingOfCourtPlayers', function($q) {
				$q->where('user_id', $this->id);
			})->get();

		foreach ($kocTournaments as $tournament) {
			$stats['total']++;
			$stats['by_type']['king_of_court'] = ($stats['by_type']['king_of_court'] ?? 0) + 1;

			// 1-е место по total_points (как в лидерборде KOC)
			$winner = $tournament->kingOfCourtPlayers()
				->orderBy('total_points', 'desc')
				->first();
			if ($winner && $winner->user_id === $this->id) {
				$stats['wins']++;
			}
		}

		// Групповой
		$teamTournaments = \App\Models\Tournament::where('type', 'team')
			->where('status', 'completed')
			->whereHas('teams', function($q) {
				$q->where('player1_id', $this->id)
				  ->orWhere('player2_id', $this->id);
			})->get();

		foreach ($teamTournaments as $tournament) {
			$stats['total']++;
			$stats['by_type']['team'] = ($stats['by_type']['team'] ?? 0) + 1;
			
			// Проверяем победителя финала
			$finalMatch = $tournament->playoffMatches()->where('stage', 'final')->first();
			if ($finalMatch && $finalMatch->winner) {
				if ($finalMatch->winner->player1_id === $this->id || $finalMatch->winner->player2_id === $this->id) {
					$stats['wins']++;
				}
			}
		}

		return $stats;
	}

	/**
	 * Лучший партнёр
	 */
	public function getBestPartner(): ?array
	{
		$partners = [];
		$matchHistory = $this->getMatchHistory();

		foreach ($matchHistory as $match) {
			if (empty($match['partner'])) continue;
			
			$partnerName = $match['partner'];
			if (!isset($partners[$partnerName])) {
				$partners[$partnerName] = ['wins' => 0, 'total' => 0];
			}
			$partners[$partnerName]['total']++;
			if ($match['won']) {
				$partners[$partnerName]['wins']++;
			}
		}

		if (empty($partners)) return null;

		uasort($partners, fn($a, $b) => $b['wins'] <=> $a['wins']);
		$bestPartnerName = array_key_first($partners);

		return [
			'name' => $bestPartnerName,
			'wins' => $partners[$bestPartnerName]['wins'],
			'total' => $partners[$bestPartnerName]['total'],
		];
	}

	/**
	 * Серия побед/поражений
	 */
	public function getCurrentStreak(): array
	{
		$matchHistory = $this->getMatchHistory();

		if (empty($matchHistory)) {
			return ['type' => 'none', 'count' => 0];
		}

		$firstResult = $matchHistory[0]['won'];
		$count = 0;

		foreach ($matchHistory as $match) {
			if ($match['won'] === $firstResult) {
				$count++;
			} else {
				break;
			}
		}

		return [
			'type' => $firstResult ? 'win' : 'loss',
			'count' => $count,
		];
	}

	/**
	 * Тренд рейтинга
	 */
	public function getRatingTrend(): string
	{
		$history = $this->ratingHistory()->take(5)->get();

		if ($history->count() < 2) return 'stable';

		$totalChange = $history->sum('change');

		if ($totalChange > 20) return 'up';
		if ($totalChange < -20) return 'down';
		return 'stable';
	}

	/**
	 * Достижения
	 */
	public function getAchievements(): array
	{
		$achievements = [];
		$stats = $this->getAllMatchesStats();
		$tournamentStats = $this->getTournamentStats();
		$streak = $this->getCurrentStreak();

		if ($stats['won'] >= 1) {
			$achievements[] = ['icon' => '🎯', 'name' => 'Первая победа', 'desc' => 'Выиграл первый матч'];
		}

		if ($stats['won'] >= 10) {
			$achievements[] = ['icon' => '⭐', 'name' => 'Десятка', 'desc' => '10 побед'];
		}

		if ($stats['won'] >= 50) {
			$achievements[] = ['icon' => '🌟', 'name' => 'Полтинник', 'desc' => '50 побед'];
		}

		if ($stats['total'] >= 100) {
			$achievements[] = ['icon' => '💯', 'name' => 'Сотня', 'desc' => '100 матчей сыграно'];
		}

		if ($tournamentStats['wins'] >= 1) {
			$achievements[] = ['icon' => '🏆', 'name' => 'Чемпион', 'desc' => 'Победитель турнира'];
		}

		if ($tournamentStats['wins'] >= 5) {
			$achievements[] = ['icon' => '👑', 'name' => 'Король', 'desc' => '5 турниров выиграно'];
		}

		if ($streak['type'] === 'win' && $streak['count'] >= 5) {
			$achievements[] = ['icon' => '🔥', 'name' => 'В ударе', 'desc' => 'Серия из 5+ побед'];
		}

		if ($stats['total'] >= 10 && $this->winRate() >= 60) {
			$achievements[] = ['icon' => '💪', 'name' => 'Стабильный', 'desc' => 'Винрейт 60%+'];
		}

		return $achievements;
	}
	
	/**
	 * Получить детальную историю турниров с матчами
	 */
	public function getTournamentHistory(): array
	{
		$history = [];
		
		// Получаем историю рейтинга (турниры в которых участвовал)
		$ratingHistory = $this->ratingHistory()
			->with('tournament')
			->orderBy('created_at', 'desc')
			->take(10)
			->get();
		
		foreach ($ratingHistory as $record) {
			if (!$record->tournament) continue;
			
			$tournament = $record->tournament;
			$matches = $this->getMatchesForTournament($tournament);
			
			$history[] = [
				'id' => $tournament->id,
				'name' => $tournament->name,
				'date' => $record->created_at->format('d.m.Y'),
				'change' => $record->change,
				'rating_after' => $record->rating_after,
				'matches' => $matches,
			];
		}
		
		return $history;
	}

	/**
	 * Получить матчи пользователя в конкретном турнире
	 */
	protected function getMatchesForTournament($tournament): array
	{
		$matches = [];
		$type = $tournament->type ?? 'americano';
		
		if ($type === 'americano') {
			$matches = $this->getAmericanoMatches($tournament);
		} elseif ($type === 'mexicano') {
			$matches = $this->getMexicanoMatches($tournament);
		} elseif ($type === 'team') {
			$matches = $this->getTeamMatches($tournament);
		}
		
		return $matches;
	}

	/**
	 * Получить матчи Американо
	 */
	protected function getAmericanoMatches($tournament): array
	{
		$matches = [];
		
		// Групповые матчи
		$groups = $tournament->groups()->with(['rounds.matches'])->get();
		
		foreach ($groups as $group) {
			foreach ($group->rounds as $round) {
				foreach ($round->matches as $match) {
					if ($match->status !== 'completed') continue;
					
					$isInMatch = in_array($this->id, [
						$match->team1_player1_id,
						$match->team1_player2_id,
						$match->team2_player1_id,
						$match->team2_player2_id,
					]);
					
					if (!$isInMatch) continue;
					
					$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;
					
					$matches[] = $this->formatMatch($match, $isTeam1, "Раунд {$round->round_number}");
				}
			}
		}
		
		// Плей-офф матчи
		$playoffMatches = $tournament->playoffMatches()
			->where('status', 'completed')
			->orderBy('stage')
			->orderBy('match_number')
			->get();
		
		foreach ($playoffMatches as $match) {
			$isInMatch = in_array($this->id, [
				$match->team1_player1_id,
				$match->team1_player2_id,
				$match->team2_player1_id,
				$match->team2_player2_id,
			]);
			
			if (!$isInMatch) continue;
			
			$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;
			$stageName = $match->stage === 'Полуфинал' ? 'Полуфинал' : 'Финал';
			
			$matches[] = $this->formatMatch($match, $isTeam1, $stageName);
		}
		
		return $matches;
	}

	/**
	 * Получить матчи Мексикано
	 */
	protected function getMexicanoMatches($tournament): array
	{
		$matches = [];
		
		$rounds = $tournament->mexicanoRounds()->with('matches')->orderBy('round_number')->get();
		
		foreach ($rounds as $round) {
			foreach ($round->matches as $match) {
				if ($match->status !== 'completed') continue;
				
				$isInMatch = in_array($this->id, [
					$match->team1_player1_id,
					$match->team1_player2_id,
					$match->team2_player1_id,
					$match->team2_player2_id,
				]);
				
				if (!$isInMatch) continue;
				
				$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;
				
				$matches[] = $this->formatMatch($match, $isTeam1, "Раунд {$round->round_number}");
			}
		}
		
		// Плей-офф
		$playoffMatches = $tournament->playoffMatches()
			->where('status', 'completed')
			->orderBy('stage')
			->orderBy('match_number')
			->get();
		
		foreach ($playoffMatches as $match) {
			$isInMatch = in_array($this->id, [
				$match->team1_player1_id,
				$match->team1_player2_id,
				$match->team2_player1_id,
				$match->team2_player2_id,
			]);
			
			if (!$isInMatch) continue;
			
			$isTeam1 = $match->team1_player1_id == $this->id || $match->team1_player2_id == $this->id;
			$stageName = $match->stage === 'Полуфинал' ? 'Полуфинал' : 'Финал';
			
			$matches[] = $this->formatMatch($match, $isTeam1, $stageName);
		}
		
		return $matches;
	}

	/**
	 * Получить матчи командного турнира
	 */
	protected function getTeamMatches($tournament): array
	{
		$matches = [];
		
		$teamIds = \App\Models\TournamentTeam::where('tournament_id', $tournament->id)
			->where(function($q) {
				$q->where('player1_id', $this->id)->orWhere('player2_id', $this->id);
			})
			->pluck('id');
		
		if ($teamIds->isEmpty()) return $matches;
		
		// Групповые матчи
		$groupMatches = \App\Models\TournamentGroupMatch::where('status', 'completed')
			->whereHas('group', fn($q) => $q->where('tournament_id', $tournament->id))
			->where(function($q) use ($teamIds) {
				$q->whereIn('team1_id', $teamIds)->orWhereIn('team2_id', $teamIds);
			})
			->with(['team1.player1', 'team1.player2', 'team2.player1', 'team2.player2'])
			->get();
		
		foreach ($groupMatches as $match) {
			$isTeam1 = $teamIds->contains($match->team1_id);
			$matches[] = $this->formatTeamMatch($match, $isTeam1, "Групповой этап");
		}
		
		// Плей-офф
		$playoffMatches = $tournament->playoffMatches()
			->where('status', 'completed')
			->where(function($q) use ($teamIds) {
				$q->whereIn('team1_id', $teamIds)->orWhereIn('team2_id', $teamIds);
			})
			->with(['team1.player1', 'team1.player2', 'team2.player1', 'team2.player2'])
			->orderBy('stage')
			->get();
		
		foreach ($playoffMatches as $match) {
			$isTeam1 = $teamIds->contains($match->team1_id);
			$stageName = $match->stage === 'Полуфинал' ? 'Полуфинал' : 'Финал';
			$matches[] = $this->formatTeamMatch($match, $isTeam1, $stageName);
		}
		
		return $matches;
	}

	/**
	 * Форматировать матч (Американо/Мексикано)
	 */
	protected function formatMatch($match, bool $isTeam1, string $round): array
	{
		$myScore = $isTeam1 ? $match->team1_score : $match->team2_score;
		$oppScore = $isTeam1 ? $match->team2_score : $match->team1_score;
		$won = $myScore > $oppScore;
		
		// Мой партнёр
		if ($isTeam1) {
			$partnerId = $match->team1_player1_id == $this->id ? $match->team1_player2_id : $match->team1_player1_id;
			$opp1Id = $match->team2_player1_id;
			$opp2Id = $match->team2_player2_id;
		} else {
			$partnerId = $match->team2_player1_id == $this->id ? $match->team2_player2_id : $match->team2_player1_id;
			$opp1Id = $match->team1_player1_id;
			$opp2Id = $match->team1_player2_id;
		}
		
		$partner = \App\Models\User::find($partnerId);
		$opp1 = \App\Models\User::find($opp1Id);
		$opp2 = \App\Models\User::find($opp2Id);
		
		return [
			'round' => $round,
			'my_score' => $myScore,
			'opp_score' => $oppScore,
			'won' => $won,
			'me' => $this->formatPlayer($this),
			'partner' => $partner ? $this->formatPlayer($partner) : null,
			'opponent1' => $opp1 ? $this->formatPlayer($opp1) : null,
			'opponent2' => $opp2 ? $this->formatPlayer($opp2) : null,
		];
	}

	/**
	 * Форматировать командный матч
	 */
	protected function formatTeamMatch($match, bool $isTeam1, string $round): array
	{
		$myTeam = $isTeam1 ? $match->team1 : $match->team2;
		$oppTeam = $isTeam1 ? $match->team2 : $match->team1;
		
		$myScore = $isTeam1 ? $match->team1_score : $match->team2_score;
		$oppScore = $isTeam1 ? $match->team2_score : $match->team1_score;
		$won = $myScore > $oppScore;
		
		$partnerId = $myTeam->player1_id == $this->id ? $myTeam->player2_id : $myTeam->player1_id;
		$partner = \App\Models\User::find($partnerId);
		
		return [
			'round' => $round,
			'my_score' => $myScore,
			'opp_score' => $oppScore,
			'won' => $won,
			'me' => $this->formatPlayer($this),
			'partner' => $partner ? $this->formatPlayer($partner) : null,
			'opponent1' => $oppTeam->player1 ? $this->formatPlayer($oppTeam->player1) : null,
			'opponent2' => $oppTeam->player2 ? $this->formatPlayer($oppTeam->player2) : null,
		];
	}

	/**
	 * Форматировать игрока
	 */
	protected function formatPlayer($player): array
	{
		return [
			'id' => $player->id,
			'name' => $player->name ?? $player->first_name,
			'level' => $player->level,
			'rating' => $player->rating,
		];
	}
}