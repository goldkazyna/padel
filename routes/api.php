<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramMiniAppController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileTournamentController;

/*
|--------------------------------------------------------------------------
| Telegram Bot Webhook (без middleware!)
|--------------------------------------------------------------------------
*/
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
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

    // Защищённые роуты (требуют токен)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [MobileAuthController::class, 'logout']);
        Route::get('/auth/user', [MobileAuthController::class, 'user']);

        // Турниры
        Route::get('/tournaments', [MobileTournamentController::class, 'index']);
        Route::get('/tournaments/my', [MobileTournamentController::class, 'my']);
        Route::get('/tournaments/archive', [MobileTournamentController::class, 'archive']);
        Route::get('/tournaments/{tournament}', [MobileTournamentController::class, 'show']);
    });
});