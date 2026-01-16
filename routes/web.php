<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\Admin\ClubController;
use App\Http\Controllers\Club\TournamentController as ClubTournamentController;
use App\Http\Controllers\Club\MatchController;
use App\Http\Controllers\Club\AmericanoController;
use App\Http\Controllers\Club\MexicanoController;
use App\Http\Controllers\Club\TeamTournamentController;
use App\Http\Controllers\RatingController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\TelegramAuthController;

/*
|--------------------------------------------------------------------------
| Публичные роуты
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return view('welcome');
});
// Превью рейтинга Американо
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
        // Превью рейтинга Мексикано
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
				// Превью рейтинга Team
		Route::get('/tournaments/{tournament}/preview-rating-team', function (\App\Models\Tournament $tournament) {
			$service = app(\App\Services\TeamTournamentService::class);
			$preview = $service->previewRatingChanges($tournament);
			
			echo "<pre style='background:#1a1a1a;color:#fff;padding:20px;font-family:monospace;'>";
			echo "<b style='color:#22c55e;'>=== Групповой + Плей-офф: Превью рейтинга ===</b>\n\n";
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
		})->name('tournaments.previewRatingTeam');
        /*
/*
|--------------------------------------------------------------------------
| Авторизованные пользователи
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
	// Профиль игрока
	Route::get('/players/{player}', [App\Http\Controllers\PlayerController::class, 'show'])->name('players.show');
    /*
    |--------------------------------------------------------------------------
    | Общие роуты (все роли)
    |--------------------------------------------------------------------------
    */
    
    // Главная
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Профиль
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Турниры (для игроков)
    Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
    Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
    Route::post('/tournaments/{tournament}/register', [TournamentController::class, 'register'])->name('tournaments.register');
    Route::delete('/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');
    
    // Рейтинг
    Route::get('/rating', [RatingController::class, 'index'])->name('rating.index');

    /*
    |--------------------------------------------------------------------------
    | Админ клуба (club_admin, super_admin)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:club_admin,super_admin')->prefix('club')->name('club.')->group(function () {
        
        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'club'])->name('dashboard');
        
        /*
        |----------------------------------------------------------------------
        | ВАЖНО: Статические роуты ПЕРЕД динамическими с {tournament}
        |----------------------------------------------------------------------
        */
        Route::get('/tournaments/search-player', [TeamTournamentController::class, 'searchPlayer'])
            ->name('tournaments.searchPlayer');
        
        /*
        |----------------------------------------------------------------------
        | Турниры - CRUD
        |----------------------------------------------------------------------
        */
        Route::resource('tournaments', ClubTournamentController::class);
        
        /*
        |----------------------------------------------------------------------
        | Турниры - Участники
        |----------------------------------------------------------------------
        */
		// Модерация заявок
		Route::post('/tournaments/{tournament}/participants/{userId}/approve', [ClubTournamentController::class, 'approveParticipant'])
			->name('tournaments.participants.approve');

		Route::post('/tournaments/{tournament}/participants/{userId}/reject', [ClubTournamentController::class, 'rejectParticipant'])
			->name('tournaments.participants.reject');

		Route::post('/tournaments/{tournament}/participants/approve-all', [ClubTournamentController::class, 'approveAllParticipants'])
			->name('tournaments.participants.approveAll');
        Route::delete('/tournaments/{tournament}/participants/{user}', [ClubTournamentController::class, 'removeParticipant'])
            ->name('tournaments.participants.remove');
        Route::post('/tournaments/{tournament}/add-test-players', [ClubTournamentController::class, 'addTestPlayers'])
            ->name('tournaments.addTestPlayers');
		Route::get('/tournaments/{tournament}/search-players', [ClubTournamentController::class, 'searchPlayers'])
			->name('tournaments.searchPlayers');
		Route::post('/tournaments/{tournament}/participants/add', [ClubTournamentController::class, 'addParticipant'])
			->name('tournaments.participants.add');
		Route::put('/tournaments/{tournament}/participants/{userId}/replace', [ClubTournamentController::class, 'replaceParticipant'])
			->name('tournaments.participants.replace');
        /*
        |----------------------------------------------------------------------
        | Турниры - Управление
        |----------------------------------------------------------------------
        */
        Route::post('/tournaments/{tournament}/start', [ClubTournamentController::class, 'start'])
            ->name('tournaments.start');
        Route::post('/tournaments/{tournament}/finish', [ClubTournamentController::class, 'finish'])
            ->name('tournaments.finish');
		Route::post('/tournaments/{tournament}/publish-channel', [ClubTournamentController::class, 'publishToChannel'])
			->name('tournaments.publishChannel');
        
        /*
        |----------------------------------------------------------------------
        | Американо
        |----------------------------------------------------------------------
        */
        Route::post('/americano/match/{match}/score', [AmericanoController::class, 'saveScore'])
            ->name('americano.saveScore');
        Route::put('/americano/match/{match}/score', [AmericanoController::class, 'updateScore'])
            ->name('americano.updateScore');
        Route::post('/americano/tournament/{tournament}/generate-playoff', [AmericanoController::class, 'generatePlayoff'])
		->name('americano.generatePlayoff');
		Route::post('/americano/playoff-match/{match}/score', [AmericanoController::class, 'savePlayoffScore'])
		->name('americano.savePlayoffScore');
		Route::put('/americano/playoff-match/{match}/score', [AmericanoController::class, 'updatePlayoffScore'])
		->name('americano.updatePlayoffScore');
        
        /*
        |----------------------------------------------------------------------
        | Мексикано
        |----------------------------------------------------------------------
        */
        Route::post('/mexicano/match/{match}/score', [MexicanoController::class, 'saveScore'])
            ->name('mexicano.saveScore');
        Route::put('/mexicano/match/{match}/score', [MexicanoController::class, 'updateScore'])
            ->name('mexicano.updateScore');
        Route::post('/mexicano/tournament/{tournament}/next-round', [MexicanoController::class, 'generateNextRound'])
            ->name('mexicano.nextRound');
        
        // Плей-офф Мексикано
		Route::post('/mexicano/tournament/{tournament}/generate-playoff', [MexicanoController::class, 'generatePlayoff'])
			->name('mexicano.generatePlayoff');
		Route::post('/mexicano/playoff-match/{match}/score', [MexicanoController::class, 'savePlayoffScore'])
			->name('mexicano.savePlayoffScore');
		Route::put('/mexicano/playoff-match/{match}/score', [MexicanoController::class, 'updatePlayoffScore'])
			->name('mexicano.updatePlayoffScore');
        
		
		
/*
		|----------------------------------------------------------------------
		| Групповой + Плей-офф (Team)
		|----------------------------------------------------------------------
		*/
		Route::post('/tournaments/{tournament}/add-team', [TeamTournamentController::class, 'addTeam'])
			->name('tournaments.addTeam');
		Route::delete('/tournaments/{tournament}/remove-team/{team}', [TeamTournamentController::class, 'removeTeam'])
			->name('tournaments.removeTeam');
		Route::post('/tournaments/{tournament}/add-test-teams', [TeamTournamentController::class, 'addTestTeams'])
			->name('tournaments.addTestTeams');
		Route::post('/team/group-match/{match}/score', [TeamTournamentController::class, 'saveGroupMatchScore'])
			->name('team.saveGroupMatchScore');
		Route::put('/team/group-match/{match}/score', [TeamTournamentController::class, 'updateGroupMatchScore'])
			->name('team.updateGroupMatchScore');
		Route::post('/tournaments/{tournament}/generate-playoff', [TeamTournamentController::class, 'generatePlayoff'])
			->name('team.generatePlayoff');
        Route::post('/team/playoff-match/{match}/score', [TeamTournamentController::class, 'savePlayoffScore'])
			->name('team.savePlayoffScore');
		Route::put('/team/playoff-match/{match}/score', [TeamTournamentController::class, 'updatePlayoffScore'])
			->name('team.updatePlayoffScore');
        /*
        |----------------------------------------------------------------------
        | Матчи (классический турнир)
        |----------------------------------------------------------------------
        */
        Route::get('/tournaments/{tournament}/matches/create', [MatchController::class, 'create'])
            ->name('matches.create');
        Route::post('/tournaments/{tournament}/matches', [MatchController::class, 'store'])
            ->name('matches.store');
        Route::delete('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'destroy'])
            ->name('matches.destroy');
    });
        
    /*
    |--------------------------------------------------------------------------
    | Супер-админ (super_admin)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:super_admin')->prefix('admin')->name('admin.')->group(function () {
        
        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');
        
        // Клубы
        Route::resource('clubs', ClubController::class)->except(['show']);
        Route::get('/clubs/{club}/admins', [ClubController::class, 'admins'])->name('clubs.admins');
        Route::post('/clubs/{club}/admins', [ClubController::class, 'addAdmin'])->name('clubs.admins.add');
        Route::delete('/clubs/{club}/admins/{user}', [ClubController::class, 'removeAdmin'])->name('clubs.admins.remove');
        
        // Поиск игроков
        Route::get('/players/search', [ClubController::class, 'searchPlayer'])->name('players.search');
    });
    
});
// Telegram Auth
Route::get('/auth/telegram/callback', [TelegramAuthController::class, 'callback'])->name('auth.telegram.callback');
Route::get('/register/telegram', [TelegramAuthController::class, 'showRegisterForm'])->name('auth.telegram.register');
Route::post('/register/telegram', [TelegramAuthController::class, 'completeRegistration'])->name('auth.telegram.complete');


require __DIR__.'/auth.php';
