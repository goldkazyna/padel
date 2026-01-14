<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramMiniAppController;
use App\Http\Controllers\Api\TelegramWebhookController;

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
});