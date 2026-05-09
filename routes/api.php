<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramMiniAppController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileHomeController;
use App\Http\Controllers\Api\MobileRatingController;
use App\Http\Controllers\Api\MobileTournamentController;
use App\Http\Controllers\Api\MobileProfileController;
use App\Http\Controllers\Api\MobileMatchController;
use App\Http\Controllers\Api\MobileDeviceController;
use App\Http\Controllers\Api\MobileNotificationController;
use App\Http\Controllers\Api\TelegramMobileWebhookController;
use App\Http\Controllers\Api\MobileAppController;
use App\Http\Controllers\Api\MobileChallengeController;
use App\Http\Controllers\Api\MobileCourtController;
use App\Http\Controllers\Api\MobileClubController;
use App\Http\Controllers\Api\MobileAdminTournamentController;
use App\Http\Controllers\Api\MobileAdminTournamentDetailController;
use App\Http\Controllers\Api\MobileAdminUserController;

/*
|--------------------------------------------------------------------------
| Telegram Bot Webhook (без middleware!)
|--------------------------------------------------------------------------
*/
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::post('/telegram/mobile-webhook', [TelegramMobileWebhookController::class, 'handle']);
/*
|--------------------------------------------------------------------------
| Telegram Mini App API
|--------------------------------------------------------------------------
*/
Route::prefix('tg')->middleware('telegram.miniapp')->group(function () {
    Route::post('/auth', [TelegramMiniAppController::class, 'auth']);
    Route::get('/profile', [TelegramMiniAppController::class, 'profile']);
    Route::get('/tournaments', [TelegramMiniAppController::class, 'tournaments']);
    Route::get('/tournaments/{tournament}', [TelegramMiniAppController::class, 'tournamentShow']);
    Route::post('/tournaments/{tournament}/register', [TelegramMiniAppController::class, 'register']);
    Route::post('/tournaments/{tournament}/cancel', [TelegramMiniAppController::class, 'cancelRegistration']);
    Route::post('/profile/phone', [TelegramMiniAppController::class, 'savePhone']);
	Route::get('/rating', [TelegramMiniAppController::class, 'rating']);
	Route::post('/profile/name', [TelegramMiniAppController::class, 'saveName']);
	Route::get('/tournaments/{tournament}/status', [TelegramMiniAppController::class, 'registrationStatus']);
	Route::post('/team/search-partner', [TelegramMiniAppController::class, 'searchPartner']);
    Route::post('/team/register', [TelegramMiniAppController::class, 'registerTeam']);
    Route::post('/team/cancel', [TelegramMiniAppController::class, 'cancelTeam']);
});

/*
|--------------------------------------------------------------------------
| Mobile App API
|--------------------------------------------------------------------------
*/
Route::prefix('mobile')->group(function () {
    // Версия приложения (публичный)
    Route::get('/app/version', [MobileAppController::class, 'version']);

    // Авторизация (без токена)
    Route::post('/auth/send-code', [MobileAuthController::class, 'sendCode']);
    Route::post('/auth/verify-code', [MobileAuthController::class, 'verifyCode']);
    Route::post('/auth/telegram/init', [MobileAuthController::class, 'telegramInit']);
    Route::get('/auth/telegram/check', [MobileAuthController::class, 'telegramCheck']);

    // Email авторизация (без токена)
    Route::post('/auth/register', [MobileAuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [MobileAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/forgot-password', [MobileAuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('/auth/reset-password', [MobileAuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    // Google Sign-In (без токена)
    Route::post('/auth/google', [MobileAuthController::class, 'googleSignIn'])->middleware('throttle:10,1');

    // Apple Sign-In (без токена)
    Route::post('/auth/apple', [MobileAuthController::class, 'appleSignIn'])->middleware('throttle:10,1');

    // Защищённые роуты (требуют токен)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [MobileAuthController::class, 'logout']);
        Route::get('/auth/user', [MobileAuthController::class, 'user']);
        Route::post('/auth/accept-terms', [MobileAuthController::class, 'acceptTerms']);
        Route::delete('/auth/account', [MobileAuthController::class, 'deleteAccount']);

        // Главная
        Route::get('/home', [MobileHomeController::class, 'index']);

        // Админка клуба (только для club_admin данного клуба или super_admin)
        Route::get('/admin/clubs/{club}/tournaments', [MobileAdminTournamentController::class, 'index']);
        Route::post('/admin/clubs/{club}/tournaments', [MobileAdminTournamentController::class, 'store']);

        // Управление игроками клуба (нужен feature 'users')
        Route::get('/admin/clubs/{club}/users', [MobileAdminUserController::class, 'index']);
        Route::put('/admin/clubs/{club}/users/{target}', [MobileAdminUserController::class, 'update']);

        // Управление существующим турниром (Этап 3a)
        Route::get('/admin/tournaments/{tournament}', [MobileAdminTournamentDetailController::class, 'show']);
        Route::put('/admin/tournaments/{tournament}', [MobileAdminTournamentDetailController::class, 'update']);
        Route::post('/admin/tournaments/{tournament}/start', [MobileAdminTournamentDetailController::class, 'start']);
        Route::delete('/admin/tournaments/{tournament}', [MobileAdminTournamentDetailController::class, 'destroy']);

        // Участники / команды турнира (Этап 3b)
        Route::get('/admin/tournaments/{tournament}/participants', [MobileAdminTournamentDetailController::class, 'participants']);
        Route::post('/admin/tournaments/{tournament}/participants', [MobileAdminTournamentDetailController::class, 'addParticipant']);
        Route::post('/admin/tournaments/{tournament}/participants/{user}/approve', [MobileAdminTournamentDetailController::class, 'approveParticipant']);
        Route::post('/admin/tournaments/{tournament}/participants/{user}/reject', [MobileAdminTournamentDetailController::class, 'rejectParticipant']);
        Route::put('/admin/tournaments/{tournament}/participants/{user}', [MobileAdminTournamentDetailController::class, 'replaceParticipant']);
        Route::delete('/admin/tournaments/{tournament}/participants/{user}', [MobileAdminTournamentDetailController::class, 'removeParticipant']);
        Route::get('/admin/tournaments/{tournament}/players/search', [MobileAdminTournamentDetailController::class, 'searchPlayers']);
        Route::post('/admin/tournaments/{tournament}/teams/{team}/approve', [MobileAdminTournamentDetailController::class, 'approveTeam']);
        Route::post('/admin/tournaments/{tournament}/teams/{team}/reject', [MobileAdminTournamentDetailController::class, 'rejectTeam']);
        Route::delete('/admin/tournaments/{tournament}/teams/{team}', [MobileAdminTournamentDetailController::class, 'removeTeam']);

        // Матчи турнира (Этап 3c-1: Американо)
        Route::get('/admin/tournaments/{tournament}/matches', [MobileAdminTournamentDetailController::class, 'matches']);
        Route::post('/admin/tournaments/{tournament}/americano/matches/{match}/score', [MobileAdminTournamentDetailController::class, 'saveAmericanoScore']);
        Route::put('/admin/tournaments/{tournament}/americano/matches/{match}/score', [MobileAdminTournamentDetailController::class, 'updateAmericanoScore']);
        Route::post('/admin/tournaments/{tournament}/americano/playoff/{match}/score', [MobileAdminTournamentDetailController::class, 'saveAmericanoPlayoffScore']);
        Route::put('/admin/tournaments/{tournament}/americano/playoff/{match}/score', [MobileAdminTournamentDetailController::class, 'updateAmericanoPlayoffScore']);

        // Этап 3d — генерация плей-офф и завершение турнира
        Route::post('/admin/tournaments/{tournament}/playoff/generate', [MobileAdminTournamentDetailController::class, 'generatePlayoff']);
        Route::post('/admin/tournaments/{tournament}/finish', [MobileAdminTournamentDetailController::class, 'finish']);

        // KOC — ввод счёта и генерация следующего раунда
        Route::match(['POST', 'PUT'], '/admin/tournaments/{tournament}/kingofcourt/matches/{match}/score', [MobileAdminTournamentDetailController::class, 'saveKingOfCourtScore']);
        Route::post('/admin/tournaments/{tournament}/next-round', [MobileAdminTournamentDetailController::class, 'nextRound']);

        // Профиль
        Route::get('/profile', [MobileProfileController::class, 'index']);
        Route::put('/profile', [MobileProfileController::class, 'update']);
        Route::post('/profile/avatar', [MobileProfileController::class, 'avatar']);
        Route::get('/profile/quiz', [MobileProfileController::class, 'quizQuestions']);
        Route::post('/profile/quiz', [MobileProfileController::class, 'submitQuiz']);

        // Матчи
        Route::get('/matches/history', [MobileMatchController::class, 'history']);

        // Рейтинг
        Route::get('/rating', [MobileRatingController::class, 'index']);
        Route::get('/rating/growth', [MobileRatingController::class, 'growth']);
        Route::get('/rating/player/{user}', [MobileRatingController::class, 'player']);
        Route::get('/rating/player/{user}/verification', [MobileRatingController::class, 'verification']);
        Route::get('/rating/tournaments', [MobileRatingController::class, 'tournaments']);

        // Турниры
        Route::get('/tournaments', [MobileTournamentController::class, 'index']);
        Route::get('/tournaments/my', [MobileTournamentController::class, 'my']);
        Route::get('/tournaments/archive', [MobileTournamentController::class, 'archive']);
        Route::get('/tournaments/cancelled', [MobileTournamentController::class, 'cancelled']);
        Route::get('/tournaments/completed', [MobileTournamentController::class, 'completed']);
        Route::get('/tournaments/{tournament}', [MobileTournamentController::class, 'show']);
        Route::get('/tournaments/{tournament}/results', [MobileTournamentController::class, 'results']);
        Route::get('/tournaments/{tournament}/stats', [MobileTournamentController::class, 'stats']);
        Route::get('/tournaments/{tournament}/live', [MobileTournamentController::class, 'live']);
        Route::post('/tournaments/{tournament}/register', [MobileTournamentController::class, 'register']);
        Route::post('/tournaments/{tournament}/cancel', [MobileTournamentController::class, 'cancel']);
        Route::post('/tournaments/{tournament}/search-partner', [MobileTournamentController::class, 'searchPartner']);
        Route::post('/tournaments/{tournament}/register-team', [MobileTournamentController::class, 'registerTeam']);
        Route::post('/tournaments/{tournament}/cancel-team', [MobileTournamentController::class, 'cancelTeam']);
        Route::post('/tournaments/{tournament}/subscribe', [MobileTournamentController::class, 'subscribe']);
        Route::post('/tournaments/{tournament}/unsubscribe', [MobileTournamentController::class, 'unsubscribe']);

        // Устройства (FCM)
        Route::post('/devices/register', [MobileDeviceController::class, 'register']);

        // Настройки уведомлений
        Route::get('/notifications/settings', [MobileDeviceController::class, 'getSettings']);
        Route::post('/notifications/settings', [MobileDeviceController::class, 'updateSettings']);

        // Уведомления
        Route::get('/notifications/categories', [MobileNotificationController::class, 'categories']);
        Route::get('/notifications', [MobileNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [MobileNotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [MobileNotificationController::class, 'readAll']);

        // Поединки
        Route::get('/challenges', [MobileChallengeController::class, 'index']);
        Route::get('/challenges/my', [MobileChallengeController::class, 'my']);
        Route::get('/challenges/clubs', [MobileChallengeController::class, 'clubs']);
        Route::post('/challenges/search-player', [MobileChallengeController::class, 'searchPlayer']);
        Route::post('/challenges', [MobileChallengeController::class, 'store']);
        Route::get('/challenges/{challenge}', [MobileChallengeController::class, 'show']);
        Route::post('/challenges/{challenge}/join', [MobileChallengeController::class, 'join']);
        Route::post('/challenges/{challenge}/invite', [MobileChallengeController::class, 'invite']);
        Route::post('/challenges/{challenge}/accept', [MobileChallengeController::class, 'accept']);
        Route::post('/challenges/{challenge}/decline', [MobileChallengeController::class, 'decline']);
        Route::post('/challenges/{challenge}/start', [MobileChallengeController::class, 'start']);
        Route::post('/challenges/{challenge}/score', [MobileChallengeController::class, 'score']);
        Route::post('/challenges/{challenge}/confirm-score', [MobileChallengeController::class, 'confirmScore']);
        Route::post('/challenges/{challenge}/cancel', [MobileChallengeController::class, 'cancel']);
        Route::post('/challenges/{challenge}/leave', [MobileChallengeController::class, 'leave']);

        // Клубы
        Route::get('/clubs', [MobileClubController::class, 'index']);
        Route::get('/clubs/{club}', [MobileClubController::class, 'show']);
        Route::post('/clubs/{club}/toggle-hide', [MobileClubController::class, 'toggleHide']);

        // Бронирование кортов
        Route::get('/courts/clubs', [MobileCourtController::class, 'clubs']);
        Route::get('/courts/clubs/{club}/schedule', [MobileCourtController::class, 'schedule']);
        Route::get('/courts/clubs/{club}/week-occupancy', [MobileCourtController::class, 'weekOccupancy']);
        Route::post('/courts/clubs/{club}/book', [MobileCourtController::class, 'book']);
        Route::get('/courts/my-bookings', [MobileCourtController::class, 'myBookings']);
        Route::post('/courts/bookings/{booking}/cancel', [MobileCourtController::class, 'cancel']);
    });
});