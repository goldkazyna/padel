<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\GameMatch;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'birth_date',
        'gender',
        'role',
        'rating',
        'level',
        'telegram_id',
        'last_played_at',
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
    ];

    // Связь: клубы где юзер админ
    public function adminClubs()
    {
        return $this->belongsToMany(Club::class, 'club_admins');
    }
	// Связь: клубы где юзер модератор
	public function moderatorClubs()
	{
		return $this->belongsToMany(Club::class, 'club_moderators');
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
    // Полное имя
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
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
		];

		// Американо
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
			
			if ($match->team1_score > $match->team2_score) {
				$isTeam1 ? $stats['won']++ : $stats['lost']++;
			} else {
				$isTeam1 ? $stats['lost']++ : $stats['won']++;
			}
		}

		// Мексикано
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
			
			if ($match->team1_score > $match->team2_score) {
				$isTeam1 ? $stats['won']++ : $stats['lost']++;
			} else {
				$isTeam1 ? $stats['lost']++ : $stats['won']++;
			}
		}

		// Групповой турнир
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
				
				if ($match->team1_score > $match->team2_score) {
					$isTeam1 ? $stats['won']++ : $stats['lost']++;
				} else {
					$isTeam1 ? $stats['lost']++ : $stats['won']++;
				}
			}

			// Плей-офф
			$playoffMatches = \App\Models\TournamentPlayoffMatch::where('status', 'completed')
				->where(function($q) use ($teamIds) {
					$q->whereIn('team1_id', $teamIds)
					  ->orWhereIn('team2_id', $teamIds);
				})->get();

			foreach ($playoffMatches as $match) {
				$stats['total']++;
				$isTeam1 = $teamIds->contains($match->team1_id);
				
				if ($match->team1_score > $match->team2_score) {
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
}