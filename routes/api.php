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
    // Авторизация (без токена)
    Route::post('/auth/send-code', [MobileAuthController::class, 'sendCode']);
    Route::post('/auth/verify-code', [MobileAuthController::class, 'verifyCode']);
    Route::post('/auth/telegram/init', [MobileAuthController::class, 'telegramInit']);
    Route::get('/auth/telegram/check', [MobileAuthController::class, 'telegramCheck']);

    // Защищённые роуты (требуют токен)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [MobileAuthController::class, 'logout']);
        Route::get('/auth/user', [MobileAuthController::class, 'user']);

        // Главная
        Route::get('/home', [MobileHomeController::class, 'index']);

        // Профиль
        Route::get('/profile', [MobileProfileController::class, 'index']);

        // Матчи
        Route::get('/matches/history', [MobileMatchController::class, 'history']);

        // Рейтинг
        Route::get('/rating', [MobileRatingController::class, 'index']);

        // Турниры
        Route::get('/tournaments', [MobileTournamentController::class, 'index']);
        Route::get('/tournaments/my', [MobileTournamentController::class, 'my']);
        Route::get('/tournaments/archive', [MobileTournamentController::class, 'archive']);
        Route::get('/tournaments/{tournament}', [MobileTournamentController::class, 'show']);
        Route::get('/tournaments/{tournament}/results', [MobileTournamentController::class, 'results']);
        Route::post('/tournaments/{tournament}/register', [MobileTournamentController::class, 'register']);
        Route::post('/tournaments/{tournament}/cancel', [MobileTournamentController::class, 'cancel']);
        Route::post('/tournaments/{tournament}/search-partner', [MobileTournamentController::class, 'searchPartner']);
        Route::post('/tournaments/{tournament}/register-team', [MobileTournamentController::class, 'registerTeam']);
        Route::post('/tournaments/{tournament}/cancel-team', [MobileTournamentController::class, 'cancelTeam']);

        // Устройства (FCM)
        Route::post('/devices/register', [MobileDeviceController::class, 'register']);

        // Настройки уведомлений
        Route::get('/notifications/settings', [MobileDeviceController::class, 'getSettings']);
        Route::post('/notifications/settings', [MobileDeviceController::class, 'updateSettings']);

        // Уведомления
        Route::get('/notifications', [MobileNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [MobileNotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [MobileNotificationController::class, 'readAll']);
    });
});