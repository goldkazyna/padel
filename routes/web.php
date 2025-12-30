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
		Route::post('/tournaments/{tournament}/add-test-players', [ClubTournamentController::class, 'addTestPlayers'])->name('tournaments.addTestPlayers');
		Route::post('/americano/match/{match}/score', [AmericanoController::class, 'saveScore'])->name('americano.saveScore');
		Route::put('/americano/match/{match}/score', [AmericanoController::class, 'updateScore'])->name('americano.updateScore');
		
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