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
use App\Http\Controllers\Auth\DeleteAccountController;
use App\Http\Controllers\Club\CourtController;

/*
|--------------------------------------------------------------------------
| Публичные роуты
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    if (auth()->check()) {
        return redirect('/dashboard');
    }
    return redirect('/login');
});

// Удаление аккаунта (публичная страница для App Store / Google Play)
Route::get('/delete-account', [DeleteAccountController::class, 'show'])->name('delete-account');
Route::post('/delete-account/send-code', [DeleteAccountController::class, 'sendCode'])->name('delete-account.send-code');
Route::delete('/delete-account', [DeleteAccountController::class, 'destroy'])->name('delete-account.destroy');
Route::get('/delete-account/done', [DeleteAccountController::class, 'done'])->name('delete-account.done');

// Юридические документы (чистые URL для App Store / Google Play)
Route::get('/terms', function () {
    return response()->file(public_path('terms.html'));
});
Route::get('/consent', function () {
    return response()->file(public_path('consent.html'));
});
// Превью рейтинга Американо
        Route::get('/tournaments/{tournament}/preview-rating', function (\App\Models\Tournament $tournament) {
            $service = app(\App\Services\AmericanoService::class);
            $preview = $service->previewRatingChanges($tournament);
            return response(view('preview-rating', [
                'tournament' => $tournament,
                'preview' => $preview,
                'type' => 'grouped',
            ]));
        })->name('tournaments.previewRating');

        Route::get('/tournaments/{tournament}/preview-rating-mexicano', function (\App\Models\Tournament $tournament) {
            $service = app(\App\Services\MexicanoService::class);
            $preview = $service->previewRatingChanges($tournament);
            return response(view('preview-rating', [
                'tournament' => $tournament,
                'preview' => ['Мексикано' => $preview],
                'type' => 'grouped',
            ]));
        })->name('tournaments.previewRatingMexicano');

        Route::get('/tournaments/{tournament}/preview-rating-team', function (\App\Models\Tournament $tournament) {
            $service = app(\App\Services\TeamTournamentService::class);
            $preview = $service->previewRatingChangesGrouped($tournament);
            return response(view('preview-rating', [
                'tournament' => $tournament,
                'preview' => $preview,
                'type' => 'grouped',
            ]));
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
    Route::middleware('role:club_admin,club_moderator,super_admin')->prefix('club')->name('club.')->group(function () {

        // Dashboard (всегда доступен)
        Route::get('/dashboard', [DashboardController::class, 'club'])->name('dashboard');

        // Управление модераторами (только admin)
        Route::middleware('role:club_admin,super_admin')->group(function () {
            Route::middleware('club.feature:moderators')->group(function () {
                Route::get('/moderators', [App\Http\Controllers\Club\ModeratorManagerController::class, 'index'])->name('moderators.index');
                Route::post('/moderators', [App\Http\Controllers\Club\ModeratorManagerController::class, 'store'])->name('moderators.store');
                Route::put('/moderators/{user}/password', [App\Http\Controllers\Club\ModeratorManagerController::class, 'updatePassword'])->name('moderators.updatePassword');
                Route::delete('/moderators/{user}', [App\Http\Controllers\Club\ModeratorManagerController::class, 'destroy'])->name('moderators.destroy');
            });
            Route::middleware('club.feature:activity_log')->group(function () {
                Route::get('/activity-log', [App\Http\Controllers\Club\ActivityLogController::class, 'index'])->name('activityLog');
            });

            // Отчёты
            Route::get('/reports', [App\Http\Controllers\Club\ReportsController::class, 'index'])->name('reports.index');
            Route::get('/reports/export', [App\Http\Controllers\Club\ReportsController::class, 'export'])->name('reports.export');
        });

        // Кол-во необработанных бронирований (для polling)
        Route::get('/unprocessed-count', function () {
            $user = auth()->user();
            if ($user->isSuperAdmin()) {
                $club = \App\Models\Club::first();
            } elseif ($user->isClubModerator()) {
                $club = $user->moderatorClubs()->first();
            } else {
                $club = $user->adminClubs()->first();
            }
            if (!$club) return response()->json(['count' => 0, 'by_date' => []]);
            $courtIds = $club->courts()->pluck('id');
            $total = \App\Models\CourtBooking::whereIn('court_id', $courtIds)
                ->where('status', 'confirmed')
                ->where('is_processed', false)
                ->count();
            $byDate = \App\Models\CourtBooking::whereIn('court_id', $courtIds)
                ->where('status', 'confirmed')
                ->where('is_processed', false)
                ->selectRaw("date, count(*) as cnt")
                ->groupBy('date')
                ->pluck('cnt', 'date');
            return response()->json(['count' => $total, 'by_date' => $byDate]);
        })->name('unprocessedCount');

        // Пользователи
        Route::middleware('club.feature:users')->group(function () {
            Route::get('/users', [App\Http\Controllers\Club\UserController::class, 'index'])->name('users.index');
            Route::put('/users/{user}', [App\Http\Controllers\Club\UserController::class, 'update'])->name('users.update');
        });

        // Клиенты
        Route::middleware('club.feature:clients')->group(function () {
            Route::get('/clients/search', [App\Http\Controllers\Club\ClientController::class, 'search'])->name('clients.search');
            Route::get('/clients', [App\Http\Controllers\Club\ClientController::class, 'index'])->name('clients.index');
            Route::post('/clients', [App\Http\Controllers\Club\ClientController::class, 'store'])->name('clients.store');
            Route::put('/clients/{client}', [App\Http\Controllers\Club\ClientController::class, 'update'])->name('clients.update');
            Route::delete('/clients/{client}', [App\Http\Controllers\Club\ClientController::class, 'destroy'])->name('clients.destroy');
        });

        // Корты
        Route::middleware('club.feature:courts')->group(function () {
            Route::get('/courts/schedule', [CourtController::class, 'schedule'])->name('courts.schedule');
            Route::get('/courts/schedule/week', [CourtController::class, 'scheduleWeek'])->name('courts.scheduleWeek');
            Route::resource('courts', CourtController::class)->except(['create', 'edit', 'show']);
            Route::post('/courts/{court}/toggle-active', [CourtController::class, 'toggleActive'])->name('courts.toggleActive');
            Route::post('/courts/{court}/book', [CourtController::class, 'book'])->name('courts.book');
            Route::post('/courts/bookings/{booking}/cancel', [CourtController::class, 'cancelBooking'])->name('courts.cancelBooking');
            Route::put('/courts/bookings/{booking}', [CourtController::class, 'updateBooking'])->name('courts.updateBooking');
            Route::post('/courts/{court}/block', [CourtController::class, 'blockSlot'])->name('courts.blockSlot');
            Route::delete('/courts/blocks/{block}', [CourtController::class, 'unblock'])->name('courts.unblock');
            Route::put('/courts/blocks/{block}', [CourtController::class, 'updateBlock'])->name('courts.updateBlock');
        });

        // Тренеры
        Route::middleware('club.feature:coaches')->group(function () {
            Route::get('/coaches/search-users', [App\Http\Controllers\Club\CoachController::class, 'searchUsers'])->name('coaches.searchUsers');
            Route::get('/coaches', [App\Http\Controllers\Club\CoachController::class, 'index'])->name('coaches.index');
            Route::post('/coaches', [App\Http\Controllers\Club\CoachController::class, 'store'])->name('coaches.store');
            Route::put('/coaches/{user}', [App\Http\Controllers\Club\CoachController::class, 'update'])->name('coaches.update');
            Route::delete('/coaches/{user}', [App\Http\Controllers\Club\CoachController::class, 'destroy'])->name('coaches.destroy');
            Route::get('/coaches/{user}/schedule', [App\Http\Controllers\Club\CoachController::class, 'schedule'])->name('coaches.schedule');
            Route::put('/coaches/{user}/schedule', [App\Http\Controllers\Club\CoachController::class, 'updateSchedule'])->name('coaches.updateSchedule');
            Route::post('/coaches/{user}/override', [App\Http\Controllers\Club\CoachController::class, 'addOverride'])->name('coaches.addOverride');
            Route::delete('/coaches/override/{override}', [App\Http\Controllers\Club\CoachController::class, 'deleteOverride'])->name('coaches.deleteOverride');
            Route::post('/coaches/{user}/block', [App\Http\Controllers\Club\CoachController::class, 'blockSlot'])->name('coaches.blockSlot');
            Route::delete('/coaches/block/{block}', [App\Http\Controllers\Club\CoachController::class, 'unblockSlot'])->name('coaches.unblockSlot');
        });

        // Турниры
        Route::middleware('club.feature:tournaments')->group(function () {
            Route::get('/tournaments/search-player', [TeamTournamentController::class, 'searchPlayer'])
                ->name('tournaments.searchPlayer');

            Route::resource('tournaments', ClubTournamentController::class);

            // Участники
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
            Route::post('/tournaments/{tournament}/cancel', [ClubTournamentController::class, 'cancel'])
                ->name('tournaments.cancel');

            // Управление
            Route::post('/tournaments/{tournament}/start', [ClubTournamentController::class, 'start'])
                ->name('tournaments.start');
            Route::get('/tournaments/{tournament}/distribute', [ClubTournamentController::class, 'distribute'])
                ->name('tournaments.distribute');
            Route::post('/tournaments/{tournament}/start-with-groups', [ClubTournamentController::class, 'startWithGroups'])
                ->name('tournaments.startWithGroups');
            Route::post('/tournaments/{tournament}/finish', [ClubTournamentController::class, 'finish'])
                ->name('tournaments.finish');
            Route::post('/tournaments/{tournament}/publish-channel', [ClubTournamentController::class, 'publishToChannel'])
                ->name('tournaments.publishChannel');
            Route::post('/tournaments/{tournament}/send-push', [ClubTournamentController::class, 'sendPush'])
                ->name('tournaments.sendPush');
            Route::get('/tournaments/{tournament}/push-preview', [ClubTournamentController::class, 'pushPreview'])
                ->name('tournaments.pushPreview');

            // Американо
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

            // Мексикано
            Route::post('/mexicano/match/{match}/score', [MexicanoController::class, 'saveScore'])
                ->name('mexicano.saveScore');
            Route::put('/mexicano/match/{match}/score', [MexicanoController::class, 'updateScore'])
                ->name('mexicano.updateScore');
            Route::post('/mexicano/tournament/{tournament}/next-round', [MexicanoController::class, 'generateNextRound'])
                ->name('mexicano.nextRound');
            Route::post('/mexicano/tournament/{tournament}/generate-playoff', [MexicanoController::class, 'generatePlayoff'])
                ->name('mexicano.generatePlayoff');
            Route::post('/mexicano/playoff-match/{match}/score', [MexicanoController::class, 'savePlayoffScore'])
                ->name('mexicano.savePlayoffScore');
            Route::put('/mexicano/playoff-match/{match}/score', [MexicanoController::class, 'updatePlayoffScore'])
                ->name('mexicano.updatePlayoffScore');

            // Групповой + Плей-офф (Team)
            Route::post('/tournaments/{tournament}/add-team', [TeamTournamentController::class, 'addTeam'])
                ->name('tournaments.addTeam');
            Route::delete('/tournaments/{tournament}/remove-team/{team}', [TeamTournamentController::class, 'removeTeam'])
                ->name('tournaments.removeTeam');
            Route::put('/tournaments/{tournament}/update-team/{team}', [TeamTournamentController::class, 'updateTeam'])
                ->name('tournaments.updateTeam');
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

            // Матчи (классический турнир)
            Route::get('/tournaments/{tournament}/matches/create', [MatchController::class, 'create'])
                ->name('matches.create');
            Route::post('/tournaments/{tournament}/matches', [MatchController::class, 'store'])
                ->name('matches.store');
            Route::delete('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'destroy'])
                ->name('matches.destroy');

            // Модерация команд
            Route::post('/tournaments/{tournament}/teams/{team}/approve', [TeamTournamentController::class, 'approveTeam'])
                ->name('tournaments.approveTeam');
            Route::post('/tournaments/{tournament}/teams/{team}/reject', [TeamTournamentController::class, 'rejectTeam'])
                ->name('tournaments.rejectTeam');

            // Управление группами (Американо)
            Route::post('/tournaments/{tournament}/generate-groups', [App\Http\Controllers\Club\GroupController::class, 'generateGroups'])
                ->name('tournaments.generateGroups');
            Route::post('/tournaments/{tournament}/reset-groups', [App\Http\Controllers\Club\GroupController::class, 'resetGroups'])
                ->name('tournaments.resetGroups');
            Route::delete('/tournaments/{tournament}/groups/{group}/players/{player}', [App\Http\Controllers\Club\GroupController::class, 'removePlayerFromGroup'])
                ->name('tournaments.groups.removePlayer');
            Route::post('/tournaments/{tournament}/groups/{group}/players', [App\Http\Controllers\Club\GroupController::class, 'addPlayerToGroup'])
                ->name('tournaments.groups.addPlayer');
            Route::get('/tournaments/{tournament}/unassigned-players', [App\Http\Controllers\Club\GroupController::class, 'getUnassignedPlayers'])
                ->name('tournaments.unassignedPlayers');
        });
    });
    /*
	|--------------------------------------------------------------------------
	| Модератор клуба (club_moderator)
	|--------------------------------------------------------------------------
	*/
	Route::middleware(['auth', 'role:club_moderator'])->prefix('moderator')->name('moderator.')->group(function () {
		
		// Dashboard
		Route::get('/dashboard', [App\Http\Controllers\Moderator\DashboardController::class, 'index'])->name('dashboard');
		
		// Турниры (только открытые)
		Route::get('/tournaments', [App\Http\Controllers\Moderator\TournamentController::class, 'index'])->name('tournaments.index');
		Route::get('/tournaments/{tournament}', [App\Http\Controllers\Moderator\TournamentController::class, 'show'])->name('tournaments.show');
		
		// Модерация участников
		Route::post('/tournaments/{tournament}/participants/{userId}/approve', [App\Http\Controllers\Moderator\TournamentController::class, 'approveParticipant'])
			->name('tournaments.participants.approve');
		Route::post('/tournaments/{tournament}/participants/{userId}/reject', [App\Http\Controllers\Moderator\TournamentController::class, 'rejectParticipant'])
			->name('tournaments.participants.reject');
		Route::delete('/tournaments/{tournament}/participants/{user}', [App\Http\Controllers\Moderator\TournamentController::class, 'removeParticipant'])
			->name('tournaments.participants.remove');
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
		// Модераторы клуба
		Route::post('/clubs/{club}/moderators', [ClubController::class, 'addModerator'])->name('clubs.moderators.add');
		Route::delete('/clubs/{club}/moderators/{user}', [ClubController::class, 'removeModerator'])->name('clubs.moderators.remove');
    });
    
});
// Telegram Auth
Route::get('/auth/telegram/callback', [TelegramAuthController::class, 'callback'])->name('auth.telegram.callback');
Route::get('/register/telegram', [TelegramAuthController::class, 'showRegisterForm'])->name('auth.telegram.register');
Route::post('/register/telegram', [TelegramAuthController::class, 'completeRegistration'])->name('auth.telegram.complete');
// Парные турниры (team)


require __DIR__.'/auth.php';
