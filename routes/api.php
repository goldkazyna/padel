<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramMiniAppController;

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
});