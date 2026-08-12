<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\Admin\ClubController;
use App\Http\Controllers\Club\TournamentController as ClubTournamentController;
use App\Http\Controllers\Club\MatchController;
use App\Http\Controllers\Club\AmericanoController;
use App\Http\Controllers\Club\MexicanoController;
use App\Http\Controllers\Club\KingOfCourtController;
use App\Http\Controllers\Club\JustPadelItController;
use App\Http\Controllers\Club\BaliKocController;
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

// Лендинг турнира — для шаринга. Открывает deep-link «padelp://tournament/{id}»
// или редиректит в магазин если приложения нет.
Route::get('/t/{tournament}', function (\App\Models\Tournament $tournament) {
    $tournament->load('club');
    $ua = request()->header('User-Agent', '');
    $isIOS = (bool) preg_match('/iPad|iPhone|iPod/i', $ua);
    $storeUrl = $isIOS
        ? config('mobile_app.store_url_ios')
        : config('mobile_app.store_url_android');

    // Картинка для OG-превью: лого клуба → fallback общая картинка.
    $logo = $tournament->club->logo ?? null;
    if ($logo && !preg_match('#^https?://#', $logo)) {
        $logo = asset('logos/' . ltrim($logo, '/'));
    }
    $ogImage = $logo ?: asset('logos/add-padel-almaty.jpg');

    return view('tournament-share', [
        'tournament' => $tournament,
        'storeUrl' => $storeUrl,
        'ogImage' => $ogImage,
    ]);
})->name('tournament.share');

// Лендинг клуба — для шаринга. Открывает deep-link «padelp://club/{id}»
// или редиректит в магазин если приложения нет.
Route::get('/c/{club}', function (\App\Models\Club $club) {
    $ua = request()->header('User-Agent', '');
    $isIOS = (bool) preg_match('/iPad|iPhone|iPod/i', $ua);
    $storeUrl = $isIOS
        ? config('mobile_app.store_url_ios')
        : config('mobile_app.store_url_android');

    // Картинка для OG-превью: обложка → лого → fallback общая картинка.
    $resolveImage = function (?string $path): ?string {
        if (!$path) return null;
        if (preg_match('#^https?://#', $path)) return $path;          // уже полный URL
        if (str_starts_with($path, '/')) return url($path);            // /covers/x.jpg или /logos/x.jpg → полный URL
        return asset('logos/' . $path);                                // устаревшее голое имя файла (напр. «x.jpg»)
    };

    $ogImage = $resolveImage($club->cover)
        ?? $resolveImage($club->logo)
        ?? asset('logos/add-padel-almaty.jpg');

    return view('club-share', [
        'club'     => $club,
        'storeUrl' => $storeUrl,
        'ogImage'  => $ogImage,
    ]);
})->name('club.share');

// Страница скачивания приложения (QR-код ведёт сюда) — публичная.
Route::get('/download', function () {
    $ua = request()->header('User-Agent', '');
    $isIOS = (bool) preg_match('/iPad|iPhone|iPod/i', $ua);
    $isAndroid = (bool) preg_match('/android/i', $ua);

    return view('download', [
        'iosUrl' => config('mobile_app.store_url_ios'),
        'androidUrl' => config('mobile_app.store_url_android'),
        'platform' => $isIOS ? 'ios' : ($isAndroid ? 'android' : 'other'),
    ]);
})->name('app.download');
Route::get('/app', fn () => redirect()->route('app.download'));

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

        Route::get('/tournaments/{tournament}/preview-rating-kingofcourt', function (\App\Models\Tournament $tournament) {
            $service = app(\App\Services\KingOfCourtService::class);
            $preview = $service->previewRatingChanges($tournament);
            return response(view('preview-rating', [
                'tournament' => $tournament,
                'preview' => ['Король корта' => $preview],
                'type' => 'grouped',
            ]));
        })->name('tournaments.previewRatingKingOfCourt');

        Route::get('/tournaments/{tournament}/preview-rating-bali-koc', function (\App\Models\Tournament $tournament) {
            $service = app(\App\Services\BaliKocService::class);
            $preview = $service->previewRatingChanges($tournament);
            return response(view('preview-rating', [
                'tournament' => $tournament,
                'preview' => ['Bali Format' => $preview],
                'type' => 'grouped',
            ]));
        })->name('tournaments.previewRatingBaliKoc');
        /*
/*
|--------------------------------------------------------------------------
| Авторизованные пользователи
|--------------------------------------------------------------------------
*/
// shift.open — блокировка менеджера без открытой смены. Висит на всей
// авторизованной зоне, а не только на /club/*: после логина менеджер
// попадает на общий /dashboard, и на клубных страницах его перехватывать
// уже поздно — чек-лист он бы просто не увидел.
Route::middleware(['auth', 'shift.open'])->group(function () {
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
    | Тренер (coach) — своя карточка, расписание (просмотр), смена пароля
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:coach')->prefix('coach')->name('coach.')->group(function () {
        Route::get('/schedule', [App\Http\Controllers\Coach\DashboardController::class, 'index'])->name('schedule');
        Route::put('/password', [App\Http\Controllers\Coach\DashboardController::class, 'updatePassword'])->name('password');
    });

    /*
    |--------------------------------------------------------------------------
    | Админ клуба (club_admin, super_admin)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['role:club_admin,club_moderator,super_admin'])->prefix('club')->name('club.')->group(function () {

        // Чек-листы смены менеджера. Сам middleware их пропускает — на них
        // он и перенаправляет, когда смена не открыта.
        Route::get('/shift/opening', [App\Http\Controllers\Club\ShiftController::class, 'opening'])->name('shift.opening');
        Route::post('/shift/open', [App\Http\Controllers\Club\ShiftController::class, 'open'])->name('shift.open');
        Route::get('/shift/closing', [App\Http\Controllers\Club\ShiftController::class, 'closing'])->name('shift.closing');
        Route::post('/shift/close', [App\Http\Controllers\Club\ShiftController::class, 'close'])->name('shift.close');

        // Dashboard (всегда доступен)
        Route::get('/dashboard', [DashboardController::class, 'club'])->name('dashboard');

        // Настройки аккаунта (имя + смена пароля)
        Route::get('/settings', [App\Http\Controllers\Club\AccountController::class, 'index'])->name('settings');
        Route::put('/settings/profile', [App\Http\Controllers\Club\AccountController::class, 'updateProfile'])->name('settings.profile');
        Route::put('/settings/password', [App\Http\Controllers\Club\AccountController::class, 'updatePassword'])->name('settings.password');
        Route::put('/settings/club', [App\Http\Controllers\Club\AccountController::class, 'updateClubSettings'])->name('settings.club');
        Route::post('/settings/club/telegram-test', [App\Http\Controllers\Club\AccountController::class, 'testTelegram'])->name('settings.club.telegramTest');

        // Помощь / инструкции (доступны всем ролям клуба)
        Route::get('/help', [App\Http\Controllers\Club\HelpController::class, 'index'])->name('help.index');
        Route::get('/help/{slug}', [App\Http\Controllers\Club\HelpController::class, 'show'])->name('help.show');

        // Журнал действий — доступен модератору с флагом can_view_activity_log
        // (проверка доступа внутри контроллера). Поэтому вне группы только-admin.
        Route::middleware('club.feature:activity_log')->group(function () {
            Route::get('/activity-log', [App\Http\Controllers\Club\ActivityLogController::class, 'index'])->name('activityLog');
        });

        // Чек-листы смены и журнал — только админ клуба.
        Route::middleware('role:club_admin,super_admin')->group(function () {
            Route::get('/shift-checklists', [App\Http\Controllers\Club\ShiftChecklistController::class, 'index'])->name('shiftChecklists.index');
            Route::post('/shift-checklists', [App\Http\Controllers\Club\ShiftChecklistController::class, 'store'])->name('shiftChecklists.store');
            Route::put('/shift-checklists/{item}', [App\Http\Controllers\Club\ShiftChecklistController::class, 'update'])->name('shiftChecklists.update');
            Route::delete('/shift-checklists/{item}', [App\Http\Controllers\Club\ShiftChecklistController::class, 'destroy'])->name('shiftChecklists.destroy');
            Route::post('/shift-checklists/{item}/restore', [App\Http\Controllers\Club\ShiftChecklistController::class, 'restore'])->name('shiftChecklists.restore');

            Route::get('/shifts', [App\Http\Controllers\Club\ShiftJournalController::class, 'index'])->name('shifts.index');
            Route::get('/shifts/{shift}', [App\Http\Controllers\Club\ShiftJournalController::class, 'show'])->name('shifts.show');
        });

        // Управление модераторами (только admin)
        Route::middleware('role:club_admin,super_admin')->group(function () {
            Route::middleware('club.feature:moderators')->group(function () {
                Route::get('/moderators', [App\Http\Controllers\Club\ModeratorManagerController::class, 'index'])->name('moderators.index');
                Route::post('/moderators', [App\Http\Controllers\Club\ModeratorManagerController::class, 'store'])->name('moderators.store');
                Route::put('/moderators/{user}/profile', [App\Http\Controllers\Club\ModeratorManagerController::class, 'updateProfile'])->name('moderators.updateProfile');
                Route::put('/moderators/{user}/password', [App\Http\Controllers\Club\ModeratorManagerController::class, 'updatePassword'])->name('moderators.updatePassword');
                Route::put('/moderators/{user}/permissions', [App\Http\Controllers\Club\ModeratorManagerController::class, 'updatePermissions'])->name('moderators.updatePermissions');
                Route::delete('/moderators/{user}', [App\Http\Controllers\Club\ModeratorManagerController::class, 'destroy'])->name('moderators.destroy');
            });

            // Отчёты
            Route::get('/reports', [App\Http\Controllers\Club\ReportsController::class, 'index'])->name('reports.index');
            Route::get('/reports/export', [App\Http\Controllers\Club\ReportsController::class, 'export'])->name('reports.export');
            Route::get('/reports/no-phone', [App\Http\Controllers\Club\ReportsController::class, 'noPhoneBookings'])->name('reports.noPhone');

            // Дополнительные отчёты (Excel)
            Route::get('/reports/extra', [App\Http\Controllers\Club\AdditionalReportsController::class, 'index'])->name('reports.extra.index');
            Route::get('/reports/extra/{report}', [App\Http\Controllers\Club\AdditionalReportsController::class, 'download'])->name('reports.extra.download');
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
            // Выгрузка выборки игроков — доступ проверяется в контроллере
            // (только супер-админ: в файле телефоны).
            Route::get('/users/export', [App\Http\Controllers\Club\UserController::class, 'export'])->name('users.export');
            Route::put('/users/{user}', [App\Http\Controllers\Club\UserController::class, 'update'])->name('users.update');
        });

        // Клиенты
        Route::middleware('club.feature:clients')->group(function () {
            Route::get('/clients/search', [App\Http\Controllers\Club\ClientController::class, 'search'])->name('clients.search');
            Route::get('/clients/export', [App\Http\Controllers\Club\ClientController::class, 'export'])->name('clients.export');
            Route::get('/clients/import', [App\Http\Controllers\Club\ClientImportController::class, 'showForm'])->name('clients.import.form');
            Route::post('/clients/import', [App\Http\Controllers\Club\ClientImportController::class, 'import'])->name('clients.import');
            Route::get('/clients/duplicates', [App\Http\Controllers\Club\ClientController::class, 'duplicates'])->name('clients.duplicates');
            Route::post('/clients/duplicates/merge', [App\Http\Controllers\Club\ClientController::class, 'mergeDuplicates'])->name('clients.duplicates.merge');
            Route::get('/clients/cards', [App\Http\Controllers\Club\ClientController::class, 'cardsOverview'])->name('clients.cards');
            Route::post('/clients/cards/{card}/archive', [App\Http\Controllers\Club\ClientController::class, 'archiveCard'])->name('clients.cards.archive');
            Route::get('/clients/groups', [App\Http\Controllers\Club\ClientController::class, 'groupsOverview'])->name('clients.groups');
            Route::get('/clients', [App\Http\Controllers\Club\ClientController::class, 'index'])->name('clients.index');
            Route::get('/clients/{client}/bookings', [App\Http\Controllers\Club\ClientController::class, 'bookings'])->name('clients.bookings');
            Route::post('/clients', [App\Http\Controllers\Club\ClientController::class, 'store'])->name('clients.store');
            Route::put('/clients/{client}', [App\Http\Controllers\Club\ClientController::class, 'update'])->name('clients.update');
            Route::put('/clients/{client}/unlink-app-user', [App\Http\Controllers\Club\ClientController::class, 'unlinkAppUser'])->name('clients.unlinkAppUser');
            Route::delete('/clients/{client}', [App\Http\Controllers\Club\ClientController::class, 'destroy'])->name('clients.destroy');
        });

        // Инвентарь клуба
        Route::middleware('club.feature:inventory')->group(function () {
            Route::get('/inventory', [App\Http\Controllers\Club\InventoryController::class, 'index'])->name('inventory.index');
            Route::post('/inventory', [App\Http\Controllers\Club\InventoryController::class, 'store'])->name('inventory.store');
            Route::put('/inventory/{item}', [App\Http\Controllers\Club\InventoryController::class, 'update'])->name('inventory.update');
            Route::delete('/inventory/{item}', [App\Http\Controllers\Club\InventoryController::class, 'destroy'])->name('inventory.destroy');
        });

        // Клубные карты
        Route::get('/cards', [App\Http\Controllers\Club\ClubCardTypeController::class, 'index'])->name('cards.index');
        Route::post('/card-types', [App\Http\Controllers\Club\ClubCardTypeController::class, 'store'])->name('cardTypes.store');
        Route::put('/card-types/{cardType}', [App\Http\Controllers\Club\ClubCardTypeController::class, 'update'])->name('cardTypes.update');
        Route::delete('/card-types/{cardType}', [App\Http\Controllers\Club\ClubCardTypeController::class, 'destroy'])->name('cardTypes.destroy');
        Route::get('/cards/for-client', [App\Http\Controllers\Club\ClubCardController::class, 'forClient'])->name('cards.forClient');
        Route::get('/cards/journal', [App\Http\Controllers\Club\ClubCardController::class, 'journal'])->name('cards.journal');
        Route::get('/cards/pending', [App\Http\Controllers\Club\ClubCardController::class, 'pending'])->name('cards.pending');
        Route::post('/cards/pending/{booking}/charge', [App\Http\Controllers\Club\ClubCardController::class, 'charge'])->name('cards.pending.charge');
        Route::post('/cards/pending/{booking}/skip', [App\Http\Controllers\Club\ClubCardController::class, 'skip'])->name('cards.pending.skip');
        Route::get('/cards/unlinked', [App\Http\Controllers\Club\ClubCardController::class, 'unlinked'])->name('cards.unlinked');
        Route::get('/cards/{card}/history', [App\Http\Controllers\Club\ClubCardController::class, 'history'])->name('cards.history');
        Route::post('/cards', [App\Http\Controllers\Club\ClubCardController::class, 'store'])->name('cards.issue');
        Route::put('/cards/{card}/expiry', [App\Http\Controllers\Club\ClubCardController::class, 'updateExpiry'])->name('cards.updateExpiry');
        Route::delete('/cards/{card}', [App\Http\Controllers\Club\ClubCardController::class, 'destroy'])->name('cards.destroy');

        // Счета клиентам (платёжные ссылки Plexy). Доступны админу клуба и
        // менеджеру — счёт чаще всего выставляют на ресепшене.
        Route::get('/payments', [App\Http\Controllers\Club\PaymentLinkController::class, 'index'])->name('payments.index');
        Route::get('/payments/clients', [App\Http\Controllers\Club\PaymentLinkController::class, 'clients'])->name('payments.clients');
        Route::post('/payments', [App\Http\Controllers\Club\PaymentLinkController::class, 'store'])->name('payments.store');
        Route::post('/payments/{link}/sync', [App\Http\Controllers\Club\PaymentLinkController::class, 'sync'])->name('payments.sync');
        Route::delete('/payments/{link}', [App\Http\Controllers\Club\PaymentLinkController::class, 'cancel'])->name('payments.cancel');

        // Сертификаты клуба
        Route::get('/certificates', [App\Http\Controllers\Club\CertificateController::class, 'index'])->name('certificates.index');
        Route::post('/certificates', [App\Http\Controllers\Club\CertificateController::class, 'store'])->name('certificates.store');
        Route::get('/certificates/design', [App\Http\Controllers\Club\CertificateController::class, 'design'])->name('certificates.design');
        Route::post('/certificates/design', [App\Http\Controllers\Club\CertificateController::class, 'designUpdate'])->name('certificates.design.update');
        Route::get('/certificates/for-client', [App\Http\Controllers\Club\CertificateController::class, 'forClient'])->name('certificates.forClient');
        Route::get('/clients/{client}/certificates', [App\Http\Controllers\Club\CertificateController::class, 'client'])->name('certificates.client');
        Route::post('/certificates/{certificate}/redeem', [App\Http\Controllers\Club\CertificateController::class, 'redeem'])->name('certificates.redeem');
        Route::get('/certificates/{certificate}', [App\Http\Controllers\Club\CertificateController::class, 'show'])->name('certificates.show');
        Route::delete('/certificates/{certificate}', [App\Http\Controllers\Club\CertificateController::class, 'destroy'])->name('certificates.destroy');

        // Групповые занятия
        Route::middleware('club.feature:groups')->group(function () {
            Route::get('/groups', [App\Http\Controllers\Club\ClubGroupController::class, 'index'])->name('groups.index');
            Route::get('/groups/remains', [App\Http\Controllers\Club\ClubGroupController::class, 'remains'])->name('groups.remains');
            Route::get('/groups/journal', [App\Http\Controllers\Club\ClubGroupController::class, 'journal'])->name('groups.journal');
            Route::post('/groups', [App\Http\Controllers\Club\ClubGroupController::class, 'store'])->name('groups.store');
            Route::get('/groups/{group}', [App\Http\Controllers\Club\ClubGroupController::class, 'show'])->name('groups.show');
            Route::get('/groups/{group}/schedule', [App\Http\Controllers\Club\ClubGroupController::class, 'schedule'])->name('groups.schedule');
            Route::put('/groups/{group}', [App\Http\Controllers\Club\ClubGroupController::class, 'update'])->name('groups.update');
            Route::delete('/groups/{group}', [App\Http\Controllers\Club\ClubGroupController::class, 'destroy'])->name('groups.destroy');
            Route::post('/groups/{group}/archive', [App\Http\Controllers\Club\ClubGroupController::class, 'archive'])->name('groups.archive');
            Route::post('/groups/{group}/unarchive', [App\Http\Controllers\Club\ClubGroupController::class, 'unarchive'])->name('groups.unarchive');
            Route::post('/groups/{group}/members', [App\Http\Controllers\Club\ClubGroupController::class, 'addMember'])->name('groups.members.store');
            Route::put('/groups/{group}/members/{member}', [App\Http\Controllers\Club\ClubGroupController::class, 'updateMember'])->name('groups.members.update');
            Route::post('/groups/{group}/members/{member}/enroll', [App\Http\Controllers\Club\ClubGroupController::class, 'enroll'])->name('groups.members.enroll');
            Route::delete('/groups/{group}/members/{member}', [App\Http\Controllers\Club\ClubGroupController::class, 'removeMember'])->name('groups.members.destroy');
            Route::post('/groups/{group}/members/{member}/freeze', [App\Http\Controllers\Club\ClubGroupController::class, 'freezeMember'])->name('groups.members.freeze');
            Route::delete('/groups/{group}/members/{member}/freezes/{freeze}', [App\Http\Controllers\Club\ClubGroupController::class, 'unfreezeMember'])->name('groups.members.unfreeze');
            Route::get('/group-sessions', [App\Http\Controllers\Club\GroupSessionController::class, 'index'])->name('groupSessions.index');
            Route::get('/group-sessions/report', [App\Http\Controllers\Club\GroupSessionController::class, 'report'])->name('groupSessions.report');
            Route::post('/group-sessions', [App\Http\Controllers\Club\GroupSessionController::class, 'store'])->name('groupSessions.store');
            Route::get('/group-sessions/{session}', [App\Http\Controllers\Club\GroupSessionController::class, 'show'])->name('groupSessions.show');
            Route::post('/group-sessions/{session}/conduct', [App\Http\Controllers\Club\GroupSessionController::class, 'conduct'])->name('groupSessions.conduct');
            Route::post('/group-sessions/{session}/cancel', [App\Http\Controllers\Club\GroupSessionController::class, 'cancel'])->name('groupSessions.cancel');
            Route::post('/group-sessions/{session}/trial-guest', [App\Http\Controllers\Club\GroupSessionController::class, 'addTrialGuest'])->name('groupSessions.trialGuest');
            Route::delete('/group-sessions/{session}/trial-guest/{attendance}', [App\Http\Controllers\Club\GroupSessionController::class, 'removeTrialGuest'])->name('groupSessions.trialGuest.remove');
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
            Route::get('/coaches/{user}/edit', [App\Http\Controllers\Club\CoachController::class, 'edit'])->name('coaches.edit');
            Route::put('/coaches/{user}', [App\Http\Controllers\Club\CoachController::class, 'update'])->name('coaches.update');
            Route::put('/coaches/{user}/credentials', [App\Http\Controllers\Club\CoachController::class, 'updateCredentials'])->name('coaches.credentials');
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
            Route::post('/tournaments/{tournament}/participants/{userId}/move', [ClubTournamentController::class, 'moveParticipant'])
                ->name('tournaments.participants.move');
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
            // Приглашения игроков (как в мобильной админке) — все индивидуальные турниры
            Route::post('/tournaments/{tournament}/invite', [ClubTournamentController::class, 'invite'])
                ->name('tournaments.invite');
            Route::delete('/tournaments/{tournament}/invitations/{invitation}', [ClubTournamentController::class, 'cancelInvitation'])
                ->name('tournaments.invitations.cancel');
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
            Route::post('/mexicano/tournament/{tournament}/finish-early', [MexicanoController::class, 'finishEarly'])
                ->name('mexicano.finishEarly');
            Route::post('/mexicano/playoff-match/{match}/score', [MexicanoController::class, 'savePlayoffScore'])
                ->name('mexicano.savePlayoffScore');
            Route::put('/mexicano/playoff-match/{match}/score', [MexicanoController::class, 'updatePlayoffScore'])
                ->name('mexicano.updatePlayoffScore');

            // Americano Flex
            Route::post('/tournaments/{tournament}/flex/start', [App\Http\Controllers\Club\AmericanoFlexController::class, 'start'])->name('tournaments.flex.start');
            Route::post('/tournaments/{tournament}/flex/next-round', [App\Http\Controllers\Club\AmericanoFlexController::class, 'nextRound'])->name('tournaments.flex.nextRound');
            Route::post('/tournaments/{tournament}/flex/complete', [App\Http\Controllers\Club\AmericanoFlexController::class, 'complete'])->name('tournaments.flex.complete');
            Route::post('/tournaments/flex/matches/{match}/score', [App\Http\Controllers\Club\AmericanoFlexController::class, 'saveScore'])->name('tournaments.flex.matches.score');
            Route::put('/tournaments/flex/matches/{match}/score', [App\Http\Controllers\Club\AmericanoFlexController::class, 'updateScore'])->name('tournaments.flex.matches.updateScore');

            // Король корта
            Route::get('/kingofcourt/{tournament}/pairs', [KingOfCourtController::class, 'pairs'])
                ->name('kingofcourt.pairs');
            Route::post('/kingofcourt/{tournament}/pairs', [KingOfCourtController::class, 'storePairs'])
                ->name('kingofcourt.storePairs');
            Route::post('/kingofcourt/match/{match}/score', [KingOfCourtController::class, 'saveScore'])
                ->name('kingofcourt.saveScore');
            Route::put('/kingofcourt/match/{match}/score', [KingOfCourtController::class, 'updateScore'])
                ->name('kingofcourt.updateScore');
            Route::post('/kingofcourt/tournament/{tournament}/next-round', [KingOfCourtController::class, 'generateNextRound'])
                ->name('kingofcourt.nextRound');

            // Just Padel It
            Route::get('/justpadelit/{tournament}/pairs', [JustPadelItController::class, 'pairs'])
                ->name('justpadelit.pairs');
            Route::post('/justpadelit/{tournament}/pairs', [JustPadelItController::class, 'storePairs'])
                ->name('justpadelit.storePairs');
            Route::get('/justpadelit/{tournament}/seeding', [JustPadelItController::class, 'seeding'])
                ->name('justpadelit.seeding');
            Route::post('/justpadelit/{tournament}/start', [JustPadelItController::class, 'start'])
                ->name('justpadelit.start');
            Route::post('/justpadelit/match/{match}/score', [JustPadelItController::class, 'saveScore'])
                ->name('justpadelit.saveScore');
            Route::put('/justpadelit/match/{match}/score', [JustPadelItController::class, 'updateScore'])
                ->name('justpadelit.updateScore');
            Route::post('/justpadelit/tournament/{tournament}/next-round', [JustPadelItController::class, 'generateNextRound'])
                ->name('justpadelit.nextRound');

            // Ladder
            Route::get('/escalera/{tournament}/seeding', [App\Http\Controllers\Club\EscaleraController::class, 'seeding'])
                ->name('escalera.seeding');
            Route::post('/escalera/{tournament}/seeding', [App\Http\Controllers\Club\EscaleraController::class, 'saveSeeding'])
                ->name('escalera.saveSeeding');
            Route::post('/escalera/{tournament}/start', [App\Http\Controllers\Club\EscaleraController::class, 'start'])
                ->name('escalera.start');
            Route::post('/escalera/match/{match}/score', [App\Http\Controllers\Club\EscaleraController::class, 'saveScore'])
                ->name('escalera.saveScore');
            // Правка счёта идёт тем же методом: у эскалеры сохранение и
            // обновление — одна операция. Отдельный PUT нужен общему партиалу
            // club.tournaments.partials._modal_edit_score.
            Route::put('/escalera/match/{match}/score', [App\Http\Controllers\Club\EscaleraController::class, 'saveScore'])
                ->name('escalera.updateScore');
            Route::post('/escalera/{tournament}/close-round', [App\Http\Controllers\Club\EscaleraController::class, 'closeRound'])
                ->name('escalera.closeRound');
            Route::post('/escalera/{tournament}/next-round', [App\Http\Controllers\Club\EscaleraController::class, 'nextRound'])
                ->name('escalera.nextRound');
            Route::post('/escalera/{tournament}/finish', [App\Http\Controllers\Club\EscaleraController::class, 'finish'])
                ->name('escalera.finish');

            // Round Robin
            Route::post('/round-robin/match/{match}/score', [App\Http\Controllers\Club\RoundRobinController::class, 'saveScore'])
                ->name('roundRobin.saveScore');
            Route::put('/round-robin/match/{match}/score', [App\Http\Controllers\Club\RoundRobinController::class, 'updateScore'])
                ->name('roundRobin.updateScore');
            Route::post('/round-robin/tournament/{tournament}/next-round', [App\Http\Controllers\Club\RoundRobinController::class, 'generateNextRound'])
                ->name('roundRobin.nextRound');

            // Король Корта (Bali Format)
            Route::get('/bali-koc/{tournament}/pairs', [BaliKocController::class, 'pairs'])
                ->name('bali-koc.pairs');
            Route::post('/bali-koc/{tournament}/pairs', [BaliKocController::class, 'storePairs'])
                ->name('bali-koc.storePairs');
            Route::post('/bali-koc/match/{match}/score', [BaliKocController::class, 'saveScore'])
                ->name('bali-koc.saveScore');
            Route::put('/bali-koc/match/{match}/score', [BaliKocController::class, 'updateScore'])
                ->name('bali-koc.updateScore');
            Route::post('/bali-koc/tournament/{tournament}/next-round', [BaliKocController::class, 'generateNextRound'])
                ->name('bali-koc.nextRound');

            // Групповой + Плей-офф (Team)
            Route::post('/tournaments/{tournament}/add-team', [TeamTournamentController::class, 'addTeam'])
                ->name('tournaments.addTeam');
            Route::delete('/tournaments/{tournament}/remove-team/{team}', [TeamTournamentController::class, 'removeTeam'])
                ->name('tournaments.removeTeam');
            Route::put('/tournaments/{tournament}/update-team/{team}', [TeamTournamentController::class, 'updateTeam'])
                ->name('tournaments.updateTeam');
            Route::post('/tournaments/{tournament}/add-test-teams', [TeamTournamentController::class, 'addTestTeams'])
                ->name('tournaments.addTestTeams');
            // Ручной сбор пар (pairing_mode=admin)
            Route::post('/tournaments/{tournament}/pairing/create', [TeamTournamentController::class, 'createPairing'])
                ->name('tournaments.pairing.create');
            Route::post('/tournaments/{tournament}/pairing/auto', [TeamTournamentController::class, 'autoPairing'])
                ->name('tournaments.pairing.auto');
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
        Route::post('/clubs/{club}/admins/{user}/password', [ClubController::class, 'setAdminPassword'])->name('clubs.admins.password');
        
        // Поиск игроков
        Route::get('/players/search', [ClubController::class, 'searchPlayer'])->name('players.search');
		// Модераторы клуба
		Route::post('/clubs/{club}/moderators', [ClubController::class, 'addModerator'])->name('clubs.moderators.add');
		Route::delete('/clubs/{club}/moderators/{user}', [ClubController::class, 'removeModerator'])->name('clubs.moderators.remove');

        // Рекламный баннер
        Route::get('/banners', [\App\Http\Controllers\Admin\BannerController::class, 'index'])->name('banners.index');
        Route::post('/banners', [\App\Http\Controllers\Admin\BannerController::class, 'update'])->name('banners.update');

        // Служба поддержки (тикеты)
        Route::get('/tickets', [\App\Http\Controllers\Admin\TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/{ticket}', [\App\Http\Controllers\Admin\TicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/reply', [\App\Http\Controllers\Admin\TicketController::class, 'reply'])->name('tickets.reply');
        Route::post('/tickets/{ticket}/close', [\App\Http\Controllers\Admin\TicketController::class, 'close'])->name('tickets.close');
        Route::post('/tickets/{ticket}/reopen', [\App\Http\Controllers\Admin\TicketController::class, 'reopen'])->name('tickets.reopen');
        Route::post('/tickets/{ticket}/urgent', [\App\Http\Controllers\Admin\TicketController::class, 'urgent'])->name('tickets.urgent');
        Route::post('/tickets/{ticket}/category', [\App\Http\Controllers\Admin\TicketController::class, 'category'])->name('tickets.category');
    });
    
});
// Telegram Auth
Route::get('/auth/telegram/callback', [TelegramAuthController::class, 'callback'])->name('auth.telegram.callback');
Route::get('/register/telegram', [TelegramAuthController::class, 'showRegisterForm'])->name('auth.telegram.register');
Route::post('/register/telegram', [TelegramAuthController::class, 'completeRegistration'])->name('auth.telegram.complete');
// Парные турниры (team)


require __DIR__.'/auth.php';
