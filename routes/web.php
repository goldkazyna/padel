<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\Admin\ClubController;
use App\Http\Controllers\Club\TournamentController as ClubTournamentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Club\MatchController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\Club\AmericanoController;

Route::get('/', function () {
    return view('welcome');
});

// Авторизованные пользователи
Route::middleware('auth')->group(function () {

    // Главная
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Профиль
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Турниры (для всех игроков)
    Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
    Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
    Route::post('/tournaments/{tournament}/register', [TournamentController::class, 'register'])->name('tournaments.register');
    Route::delete('/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');
	// Рейтинг
	Route::get('/rating', [RatingController::class, 'index'])->name('rating.index');

	// Админ клуба
	Route::middleware('role:club_admin,super_admin')->prefix('club')->name('club.')->group(function () {
		Route::get('/dashboard', [DashboardController::class, 'club'])->name('dashboard');
		Route::resource('tournaments', ClubTournamentController::class);
		Route::delete('/tournaments/{tournament}/participants/{user}', [ClubTournamentController::class, 'removeParticipant'])
			->name('tournaments.participants.remove');
		Route::post('/tournaments/{tournament}/start', [ClubTournamentController::class, 'start'])->name('tournaments.start');
		Route::post('/tournaments/{tournament}/finish', [ClubTournamentController::class, 'finish'])->name('tournaments.finish');
		
		
		Route::get('/tournaments/{tournament}/preview-rating', function (\App\Models\Tournament $tournament) {
		$service = app(\App\Services\AmericanoService::class);
		$preview = $service->previewRatingChanges($tournament);
		
		
		echo "<pre style='background:#1a1a1a;color:#fff;padding:20px;font-family:monospace;'>";
		foreach ($preview as $groupName => $players) {
			echo "\n<b style='color:#22c55e;'>=== {$groupName} ===</b>\n\n";
			foreach ($players as $data) {
				$diff = $data['current_rating'] - $data['rating_before'];
				$sign = $diff >= 0 ? '+' : '';
				$color = $diff >= 0 ? '#22c55e' : '#ef4444';
				echo "<b>{$data['name']}</b>: {$data['rating_before']} → {$data['current_rating']} <span style='color:{$color}'>({$sign}{$diff})</span>\n";
				if (!empty($data['matches'])) {
					echo "  Матчи: " . implode(', ', $data['matches']) . "\n";
				}
				echo "\n";
			}
		}
		echo "</pre>";
		})->name('tournaments.previewRating');
		Route::get('/tournaments/{tournament}/preview-rating-mexicano', function (\App\Models\Tournament $tournament) {
			$service = app(\App\Services\MexicanoService::class);
			$preview = $service->previewRatingChanges($tournament);
			
			echo "<pre style='background:#1a1a1a;color:#fff;padding:20px;font-family:monospace;'>";
			echo "<b style='color:#22c55e;'>=== Мексикано: Превью рейтинга ===</b>\n\n";
			foreach ($preview as $playerId => $data) {
				$diff = $data['current_rating'] - $data['rating_before'];
				$sign = $diff >= 0 ? '+' : '';
				$color = $diff >= 0 ? '#22c55e' : '#ef4444';
				echo "<b>{$data['name']}</b>: {$data['rating_before']} → {$data['current_rating']} <span style='color:{$color}'>({$sign}{$diff})</span>\n";
				if (!empty($data['matches'])) {
					echo "  Матчи: " . implode(', ', $data['matches']) . "\n";
				}
				echo "\n";
			}
			echo "</pre>";
		})->name('tournaments.previewRatingMexicano');
		
		Route::get('/mexicano/debug/{tournament}', function (\App\Models\Tournament $tournament) {
			$service = app(\App\Services\MexicanoService::class);
			
			$currentRound = $tournament->mexicanoRounds()->orderBy('round_number', 'desc')->first();
			
			echo "<pre>";
			echo "Tournament ID: {$tournament->id}\n";
			echo "Tournament status: {$tournament->status}\n";
			echo "Rounds count setting: {$tournament->rounds_count}\n";
			echo "Current round: " . ($currentRound ? $currentRound->round_number : 'null') . "\n";
			echo "Current round status: " . ($currentRound ? $currentRound->status : 'null') . "\n";
			echo "canGenerateNextRound: " . ($service->canGenerateNextRound($tournament) ? 'true' : 'false') . "\n";
			echo "</pre>";
		});
		
		
		Route::get('/mexicano/debug-round/{tournament}', function (\App\Models\Tournament $tournament) {
			echo "<pre>";
			echo "Tournament ID: {$tournament->id}\n\n";
			
			$allRounds = $tournament->mexicanoRounds()->orderBy('round_number', 'asc')->get();
			
			echo "Total rounds: " . $allRounds->count() . "\n\n";
			
			foreach ($allRounds as $round) {
				echo "=== Round {$round->round_number} (ID: {$round->id}) ===\n";
				echo "Status: {$round->status}\n";
				
				$pendingCount = $round->matches()->where('status', 'pending')->count();
				$completedCount = $round->matches()->where('status', 'completed')->count();
				
				echo "Matches: {$completedCount} completed, {$pendingCount} pending\n";
				
				foreach ($round->matches as $match) {
					echo "  Match {$match->id}: status={$match->status}, score={$match->team1_score}:{$match->team2_score}\n";
				}
				echo "\n";
			}
			
			$lastRound = $tournament->mexicanoRounds()->orderBy('round_number', 'desc')->first();
			echo "Last round by orderBy desc: " . ($lastRound ? "Round {$lastRound->round_number}" : "NULL") . "\n";
			
			echo "</pre>";
		});
		
		
		
		Route::post('/tournaments/{tournament}/add-test-players', [ClubTournamentController::class, 'addTestPlayers'])->name('tournaments.addTestPlayers');
		Route::post('/americano/match/{match}/score', [AmericanoController::class, 'saveScore'])->name('americano.saveScore');
		Route::put('/americano/match/{match}/score', [AmericanoController::class, 'updateScore'])->name('americano.updateScore');
		// Mexicano
		Route::post('/mexicano/match/{match}/score', [App\Http\Controllers\Club\MexicanoController::class, 'saveScore'])->name('mexicano.saveScore');
		Route::put('/mexicano/match/{match}/score', [App\Http\Controllers\Club\MexicanoController::class, 'updateScore'])->name('mexicano.updateScore');
		Route::post('/mexicano/tournament/{tournament}/next-round', [App\Http\Controllers\Club\MexicanoController::class, 'generateNextRound'])->name('mexicano.nextRound');
		
		
		// Матчи
		Route::get('/tournaments/{tournament}/matches/create', [MatchController::class, 'create'])->name('matches.create');
		Route::post('/tournaments/{tournament}/matches', [MatchController::class, 'store'])->name('matches.store');
		Route::delete('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'destroy'])->name('matches.destroy');	
		
	});
		
    // Супер-админ
    Route::middleware('role:super_admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');
        Route::resource('clubs', ClubController::class)->except(['show']);
        Route::get('/clubs/{club}/admins', [ClubController::class, 'admins'])->name('clubs.admins');
        Route::post('/clubs/{club}/admins', [ClubController::class, 'addAdmin'])->name('clubs.admins.add');
        Route::delete('/clubs/{club}/admins/{user}', [ClubController::class, 'removeAdmin'])->name('clubs.admins.remove');
		Route::get('/players/search', [ClubController::class, 'searchPlayer'])->name('players.search');
    });
	
});

require __DIR__.'/auth.php';