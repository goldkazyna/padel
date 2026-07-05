<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramMiniAppController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileHomeController;
use App\Http\Controllers\Api\MobileRatingController;
use App\Http\Controllers\Api\MobileTournamentController;
use App\Http\Controllers\Api\MobileTournamentChatController;
use App\Http\Controllers\Api\MobileCoachController;
use App\Http\Controllers\Api\MobileTournamentInvitationController;
use App\Http\Controllers\Api\MobileProfileController;
use App\Http\Controllers\Api\MobileMatchController;
use App\Http\Controllers\Api\MobileDeviceController;
use App\Http\Controllers\Api\MobileNotificationController;
use App\Http\Controllers\Api\MobileSupportController;
use App\Http\Controllers\Api\TelegramMobileWebhookController;
use App\Http\Controllers\Api\MobileAppController;
use App\Http\Controllers\Api\MobileChallengeController;
use App\Http\Controllers\Api\MobileCourtController;
use App\Http\Controllers\Api\MobileClubController;
use App\Http\Controllers\Api\MobileAdminTournamentController;
use App\Http\Controllers\Api\MobileAdminTournamentDetailController;
use App\Http\Controllers\Api\MobileAdminUserController;
use App\Http\Controllers\Api\MobileAdminModeratorController;

/*
|--------------------------------------------------------------------------
| Telegram Bot Webhook (без middleware!)
|--------------------------------------------------------------------------
*/
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::post('/telegram/mobile-webhook', [TelegramMobileWebhookController::class, 'handle']);

// Вебхук платёжного шлюза Plexy (без auth — Plexy шлёт сюда статусы оплаты).
Route::post('/payment/webhook/plexy', [\App\Http\Controllers\Api\PaymentWebhookController::class, 'plexy']);
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
    // send-code шлёт реальные SMS на любой номер → троттлим от абуза.
    Route::post('/auth/send-code', [MobileAuthController::class, 'sendCode'])->middleware('throttle:5,1');
    Route::post('/auth/verify-code', [MobileAuthController::class, 'verifyCode'])->middleware('throttle:10,1');
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
        Route::post('/auth/account/send-delete-code', [MobileAuthController::class, 'sendDeleteCode'])->middleware('throttle:5,1');
        Route::delete('/auth/account', [MobileAuthController::class, 'deleteAccount']);

        // Смена номера телефона (подтверждение старого и нового номера по SMS)
        Route::post('/auth/phone/send-old-code', [MobileAuthController::class, 'changePhoneSendOldCode'])->middleware('throttle:5,1');
        Route::post('/auth/phone/confirm-old', [MobileAuthController::class, 'changePhoneConfirmOld']);
        Route::post('/auth/phone/send-new-code', [MobileAuthController::class, 'changePhoneSendNewCode'])->middleware('throttle:5,1');
        Route::post('/auth/phone/confirm-new', [MobileAuthController::class, 'changePhoneConfirmNew']);

        // Главная
        Route::get('/home', [MobileHomeController::class, 'index']);

        // Админка клуба (только для club_admin данного клуба или super_admin)
        Route::get('/admin/clubs/{club}/tournaments', [MobileAdminTournamentController::class, 'index']);
        Route::post('/admin/clubs/{club}/tournaments', [MobileAdminTournamentController::class, 'store']);
        // Личные (приватные) турниры обычного игрока с грантом can_create_tournaments
        Route::get('/personal/tournaments', [MobileAdminTournamentController::class, 'personalTournaments']);
        Route::post('/personal/tournaments', [MobileAdminTournamentController::class, 'storePersonal']);

        // Управление игроками клуба (нужен feature 'users')
        Route::get('/admin/clubs/{club}/users', [MobileAdminUserController::class, 'index']);
        Route::put('/admin/clubs/{club}/users/{target}', [MobileAdminUserController::class, 'update']);

        // Управление модераторами клуба (нужен feature 'moderators', только админ)
        Route::get('/admin/clubs/{club}/moderators', [MobileAdminModeratorController::class, 'index']);
        Route::get('/admin/clubs/{club}/moderators/search', [MobileAdminModeratorController::class, 'search']);
        Route::post('/admin/clubs/{club}/moderators', [MobileAdminModeratorController::class, 'store']);
        Route::put('/admin/clubs/{club}/moderators/{target}/permissions', [MobileAdminModeratorController::class, 'updatePermissions']);
        Route::delete('/admin/clubs/{club}/moderators/{target}', [MobileAdminModeratorController::class, 'destroy']);

        // Карточка клуба (редактирование, только владелец/super_admin)
        Route::get('/admin/clubs/{club}', [\App\Http\Controllers\Api\MobileAdminClubController::class, 'show']);
        Route::put('/admin/clubs/{club}', [\App\Http\Controllers\Api\MobileAdminClubController::class, 'update']);

        // Управление существующим турниром (Этап 3a)
        Route::get('/admin/tournaments/{tournament}', [MobileAdminTournamentDetailController::class, 'show']);
        Route::put('/admin/tournaments/{tournament}', [MobileAdminTournamentDetailController::class, 'update']);
        Route::post('/admin/tournaments/{tournament}/send-push', [MobileAdminTournamentDetailController::class, 'sendPush']);
        Route::post('/admin/tournaments/{tournament}/start', [MobileAdminTournamentDetailController::class, 'start']);
        Route::post('/admin/tournaments/{tournament}/restart', [MobileAdminTournamentDetailController::class, 'restart']);
        Route::post('/admin/tournaments/{tournament}/duplicate', [MobileAdminTournamentDetailController::class, 'duplicate']);
        Route::delete('/admin/tournaments/{tournament}', [MobileAdminTournamentDetailController::class, 'destroy']);

        // Участники / команды турнира (Этап 3b)
        Route::get('/admin/tournaments/{tournament}/participants', [MobileAdminTournamentDetailController::class, 'participants']);
        Route::get('/admin/tournaments/{tournament}/registration-journal', [MobileAdminTournamentDetailController::class, 'registrationJournal']);
        Route::post('/admin/tournaments/{tournament}/participants', [MobileAdminTournamentDetailController::class, 'addParticipant']);
        Route::post('/admin/tournaments/{tournament}/participants/{user}/approve', [MobileAdminTournamentDetailController::class, 'approveParticipant']);
        Route::post('/admin/tournaments/{tournament}/participants/{user}/reject', [MobileAdminTournamentDetailController::class, 'rejectParticipant']);
        Route::post('/admin/tournaments/{tournament}/participants/{user}/to-waitlist', [MobileAdminTournamentDetailController::class, 'moveToWaitlist']);
        Route::post('/admin/tournaments/{tournament}/participants/{user}/to-moderation', [MobileAdminTournamentDetailController::class, 'moveToModeration']);
        Route::post('/admin/tournaments/{tournament}/participants/{user}/move', [MobileAdminTournamentDetailController::class, 'moveParticipant']);
        Route::put('/admin/tournaments/{tournament}/participants/{user}', [MobileAdminTournamentDetailController::class, 'replaceParticipant']);
        Route::delete('/admin/tournaments/{tournament}/participants/{user}', [MobileAdminTournamentDetailController::class, 'removeParticipant']);
        Route::get('/admin/tournaments/{tournament}/players/search', [MobileAdminTournamentDetailController::class, 'searchPlayers']);
        Route::post('/admin/tournaments/{tournament}/invite', [MobileAdminTournamentDetailController::class, 'invite']);
        Route::get('/admin/tournaments/{tournament}/invitations', [MobileAdminTournamentDetailController::class, 'invitations']);
        Route::delete('/admin/tournaments/{tournament}/invitations/{invitation}', [MobileAdminTournamentDetailController::class, 'cancelInvitation']);
        Route::post('/admin/tournaments/{tournament}/teams/{team}/approve', [MobileAdminTournamentDetailController::class, 'approveTeam']);
        Route::post('/admin/tournaments/{tournament}/teams/{team}/reject', [MobileAdminTournamentDetailController::class, 'rejectTeam']);
        Route::delete('/admin/tournaments/{tournament}/teams/{team}', [MobileAdminTournamentDetailController::class, 'removeTeam']);

        // Ручной сбор пар (групповой турнир, pairing_mode=admin)
        Route::get('/admin/tournaments/{tournament}/pairing', [MobileAdminTournamentDetailController::class, 'pairingState']);
        Route::post('/admin/tournaments/{tournament}/pairing/teams', [MobileAdminTournamentDetailController::class, 'createPairing']);
        Route::delete('/admin/tournaments/{tournament}/pairing/teams/{pair}', [MobileAdminTournamentDetailController::class, 'deletePairing']);
        Route::post('/admin/tournaments/{tournament}/pairing/auto', [MobileAdminTournamentDetailController::class, 'autoPairing']);

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

        // Round Robin — ввод счёта (next-round/finish/start общие выше)
        Route::match(['POST', 'PUT'], '/admin/tournaments/{tournament}/round_robin/matches/{match}/score', [MobileAdminTournamentDetailController::class, 'saveRoundRobinScore']);

        // Bali KOC — создание пар + ввод счёта (next-round/finish общие выше)
        Route::get('/admin/tournaments/{tournament}/bali_koc/pairs', [MobileAdminTournamentDetailController::class, 'baliKocPairs']);
        Route::post('/admin/tournaments/{tournament}/bali_koc/pairs', [MobileAdminTournamentDetailController::class, 'saveBaliKocPairs']);
        Route::get('/admin/tournaments/{tournament}/kingofcourt/pairs', [MobileAdminTournamentDetailController::class, 'kocPairs']);
        Route::post('/admin/tournaments/{tournament}/kingofcourt/pairs', [MobileAdminTournamentDetailController::class, 'saveKocPairs']);

        // JPI (Just Padel It) — посев участников по рейтингу перед стартом
        Route::get('/admin/tournaments/{tournament}/justpadelit/seeding', [MobileAdminTournamentDetailController::class, 'jpiSeeding']);
        Route::get('/admin/tournaments/{tournament}/justpadelit/pairs', [MobileAdminTournamentDetailController::class, 'jpiPairs']);
        Route::post('/admin/tournaments/{tournament}/justpadelit/pairs', [MobileAdminTournamentDetailController::class, 'saveJpiPairs']);
        Route::match(['POST', 'PUT'], '/admin/tournaments/{tournament}/justpadelit/matches/{match}/score', [MobileAdminTournamentDetailController::class, 'saveJustPadelItScore']);
        Route::match(['POST', 'PUT'], '/admin/tournaments/{tournament}/bali_koc/matches/{match}/score', [MobileAdminTournamentDetailController::class, 'saveBaliKocScore']);

        // Americano Flex — счёт матча (один эндпоинт для save/update), next-round/finish общие выше
        Route::match(['POST', 'PUT'], '/admin/tournaments/{tournament}/americano_flex/matches/{match}/score', [MobileAdminTournamentDetailController::class, 'saveAmericanoFlexScore']);

        // Team (Групповой + Плей-офф) — счёт групповых матчей, плей-офф, генерация
        Route::match(['POST', 'PUT'], '/admin/tournaments/{tournament}/team/group-match/{match}/score', [MobileAdminTournamentDetailController::class, 'saveTeamGroupScore']);
        Route::match(['POST', 'PUT'], '/admin/tournaments/{tournament}/team/playoff-match/{match}/score', [MobileAdminTournamentDetailController::class, 'saveTeamPlayoffScore']);
        Route::post('/admin/tournaments/{tournament}/team/generate-playoff', [MobileAdminTournamentDetailController::class, 'generateTeamPlayoff']);

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
        // Приглашения на турнир (до wildcard {tournament})
        Route::get('/tournaments/invitations', [MobileTournamentInvitationController::class, 'index']);
        Route::get('/tournaments/invitations/count', [MobileTournamentInvitationController::class, 'count']);
        // Позвать игрока на турнир (игрок → игрок)
        Route::get('/tournaments/invitable', [MobileTournamentInvitationController::class, 'invitable']);
        Route::post('/tournaments/invitations/{invitation}/accept', [MobileTournamentInvitationController::class, 'accept']);
        Route::post('/tournaments/invitations/{invitation}/decline', [MobileTournamentInvitationController::class, 'decline']);
        Route::post('/tournaments/{tournament}/invite-player', [MobileTournamentInvitationController::class, 'invitePlayer']);
        // Ближайшая неоплаченная заявка с таймером модерации (до wildcard {tournament})
        Route::get('/tournaments/moderation-pending', [MobileTournamentController::class, 'moderationPending']);
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

        // Чат турнира
        Route::get('/tournaments/{tournament}/chat/unread-count', [MobileTournamentChatController::class, 'unreadCount']);
        Route::get('/tournaments/{tournament}/chat/messages', [MobileTournamentChatController::class, 'index']);
        Route::post('/tournaments/{tournament}/chat/messages', [MobileTournamentChatController::class, 'store']);
        Route::delete('/tournaments/{tournament}/chat/messages/{message}', [MobileTournamentChatController::class, 'destroy']);
        Route::post('/tournaments/{tournament}/chat/read', [MobileTournamentChatController::class, 'read']);

        // Устройства (FCM)
        Route::post('/devices/register', [MobileDeviceController::class, 'register']);

        // Настройки уведомлений
        // Расписание тренера (роль coach)
        Route::get('/coach/schedule', [MobileCoachController::class, 'schedule']);
        Route::get('/coach/hours-range', [MobileCoachController::class, 'hoursRange']);

        Route::get('/notifications/settings', [MobileDeviceController::class, 'getSettings']);
        Route::post('/notifications/settings', [MobileDeviceController::class, 'updateSettings']);

        // Уведомления
        Route::get('/notifications/categories', [MobileNotificationController::class, 'categories']);
        Route::get('/notifications', [MobileNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [MobileNotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [MobileNotificationController::class, 'readAll']);

        // Служба поддержки (тикеты игрока)
        Route::get('/support/unread-count', [MobileSupportController::class, 'unreadCount']);
        Route::get('/support/tickets', [MobileSupportController::class, 'index']);
        Route::post('/support/tickets', [MobileSupportController::class, 'store']);
        Route::get('/support/tickets/{ticket}', [MobileSupportController::class, 'show']);
        Route::post('/support/tickets/{ticket}/messages', [MobileSupportController::class, 'addMessage']);

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
        Route::get('/clubs/{club}/stats', [MobileClubController::class, 'stats']);
        Route::post('/clubs/{club}/toggle-hide', [MobileClubController::class, 'toggleHide']);

        // Бронирование кортов
        Route::get('/courts/clubs', [MobileCourtController::class, 'clubs']);
        Route::get('/courts/clubs/{club}/schedule', [MobileCourtController::class, 'schedule']);
        Route::get('/courts/clubs/{club}/week-occupancy', [MobileCourtController::class, 'weekOccupancy']);
        Route::post('/courts/clubs/{club}/book', [MobileCourtController::class, 'book']);
        Route::post('/courts/bookings/{booking}/create-payment', [MobileCourtController::class, 'createPayment']);
        Route::get('/courts/bookings/{booking}/payment-status', [MobileCourtController::class, 'paymentStatus']);
        Route::get('/courts/my-bookings', [MobileCourtController::class, 'myBookings']);
        Route::post('/courts/bookings/{booking}/cancel', [MobileCourtController::class, 'cancel']);
    });
});