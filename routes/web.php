<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\ClubController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Авторизованные пользователи
Route::middleware('auth')->group(function () {

    // Главная после входа
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Профиль
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Админ клуба
    Route::middleware('role:club_admin,super_admin')->prefix('club')->name('club.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'club'])->name('dashboard');
    });

    // Супер-админ
    Route::middleware('role:super_admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');
        Route::resource('clubs', ClubController::class)->except(['show']);
		
		Route::get('/clubs/{club}/admins', [ClubController::class, 'admins'])->name('clubs.admins');
		Route::post('/clubs/{club}/admins', [ClubController::class, 'addAdmin'])->name('clubs.admins.add');
		Route::delete('/clubs/{club}/admins/{user}', [ClubController::class, 'removeAdmin'])->name('clubs.admins.remove');
    });
});

require __DIR__.'/auth.php';