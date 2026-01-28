<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Padel Center</title>
    
    <!-- Telegram WebApp -->
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    
    <!-- CSS -->
    <link rel="stylesheet" href="css/base.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/components.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/nav.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/home.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/tournaments.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/rating.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/profile.css?v=<?= time() ?>">
	<link rel="stylesheet" href="css/team-registration.css?v=3">
</head>
<body>
    <div id="app">
        
        <!-- ============ ЗАГРУЗКА ============ -->
        <div id="loading-screen" class="screen active">
            <div class="loader"></div>
            <p>Загрузка...</p>
        </div>

        <!-- ============ ЗАПРОС ТЕЛЕФОНА ============ -->
        <div id="phone-request-screen" class="screen">
            <div class="phone-request-card">
                <div class="phone-icon">📱</div>
                <h2>Добро пожаловать!</h2>
                <p>Для регистрации на турниры нам нужен ваш номер телефона</p>
                <button id="share-phone-btn" class="btn btn-primary btn-block">
                    Поделиться номером
                </button>

            </div>
        </div>

        <!-- ============ ГЛАВНАЯ ============ -->
        <div id="home-screen" class="screen">
			    <div class="screen-header" style="display: flex; justify-content: space-between; align-items: center;">
					<h1 class="screen-title">Главная</h1>
					<button class="btn-icon" onclick="refreshHome()">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
						</svg>
					</button>
				</div>
            <div class="screen-content">
                <!-- Приветствие -->
                <div id="welcome-section" class="welcome-section">
                    <!-- Рендерится через JS -->
                </div>

                <!-- Быстрая статистика -->
                <div id="quick-stats" class="quick-stats">
                    <!-- Рендерится через JS -->
                </div>

                <!-- Ближайший турнир -->
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title">Турниры</h2>
                        <a href="#" class="section-link" onclick="navigateTo('tournaments'); return false;">Все</a>
                    </div>
                    <div id="next-tournament">
                        <!-- Рендерится через JS -->
                    </div>
                </div>

                <!-- Последние матчи -->
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title">Последние матчи</h2>
                    </div>
                    <div id="recent-matches">
                        <div class="empty-state">
                            <div class="empty-state-text">Раздел в разработке</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ ТУРНИРЫ ============ -->
        <div id="tournaments-screen" class="screen">
                <div class="screen-header" style="display: flex; justify-content: space-between; align-items: center;">
					<h1 class="screen-title">Турниры</h1>
					<button class="btn-icon" onclick="refreshTournaments()" title="Обновить">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
						</svg>
					</button>
				</div>
            <div class="screen-content">
                

                <!-- Список турниров -->
                <div id="tournaments-list">
                    <!-- Рендерится через JS -->
                </div>
            </div>
        </div>

        <!-- ============ ДЕТАЛИ ТУРНИРА ============ -->
        <div id="tournament-detail-screen" class="screen">
            <div class="screen-header" style="display: flex; justify-content: space-between; align-items: center;">
				<button class="btn btn-secondary" onclick="backFromTournament()" style="padding: 8px 16px;">
					← Назад
				</button>
				<button class="btn btn-secondary" onclick="refreshTournament()" style="padding: 8px 16px;">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
					</svg>
				</button>
			</div>
            <div class="screen-content">
                <div id="tournament-detail-content">
                    <!-- Рендерится через JS -->
                </div>
            </div>
        </div>

        <!-- ============ РЕЙТИНГ ============ -->
        <div id="rating-screen" class="screen">
            <div class="screen-header">
                <h1 class="screen-title">Рейтинг</h1>
            </div>
            <div class="screen-content">
                <!-- Моя позиция -->
                <div id="my-rank-card" class="my-rank-card">
                    <!-- Рендерится через JS -->
                </div>

                <!-- Топ игроков -->
                <div id="ranking-list">
                    <!-- Рендерится через JS -->
                </div>
				<!-- Пагинация -->
				<div id="rating-pagination">
					<!-- Рендерится через JS -->
				</div>
            </div>
        </div>

        <!-- ============ ПРОФИЛЬ ============ -->
        <div id="profile-screen" class="screen">
            <div class="screen-header">
                <h1 class="screen-title">Профиль</h1>
            </div>
            <div class="screen-content">
                <!-- Шапка профиля -->
                <div id="profile-header" class="profile-header-card">
                    <!-- Рендерится через JS -->
                </div>

                <!-- Статистика -->
                <div id="profile-stats" class="profile-stats">
                    <!-- Рендерится через JS -->
                </div>

                <!-- История рейтинга -->
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title">История рейтинга</h2>
                    </div>
                    <div id="rating-history">
                        <!-- Рендерится через JS -->
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ НИЖНЯЯ НАВИГАЦИЯ ============ -->
        <nav class="bottom-nav">
            <button class="nav-item active" data-screen="home">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
                <span class="nav-label">Главная</span>
            </button>
            <button class="nav-item" data-screen="tournaments">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="8" r="6"/>
                    <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
                </svg>
                <span class="nav-label">Турниры</span>
            </button>
            <button class="nav-item" data-screen="rating">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 20V10"/>
                    <path d="M18 20V4"/>
                    <path d="M6 20v-4"/>
                </svg>
                <span class="nav-label">Рейтинг</span>
            </button>
            <button class="nav-item" data-screen="profile">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <span class="nav-label">Профиль</span>
            </button>
        </nav>

    </div>
	<!-- Модалка редактирования имени -->
	<div id="edit-name-modal" class="modal">
		<div class="modal-backdrop" onclick="closeEditName()"></div>
		<div class="modal-content">
			<h3>Изменить имя</h3>
			<input type="text" id="edit-name-input" class="modal-input" placeholder="Введите имя">
			<div class="modal-buttons">
				<button class="btn btn-secondary" onclick="closeEditName()">Отмена</button>
				<button class="btn btn-primary" onclick="submitEditName()">Сохранить</button>
			</div>
		</div>
	</div>
    <!-- JS -->
    <script src="js/api.js?v=<?= time() ?>"></script>
    <script src="js/app.js?v=<?= time() ?>"></script>
    <script src="js/home.js?v=149"></script>
    <script src="js/tournaments.js?v=149"></script>
    <script src="js/rating.js?v=<?= time() ?>"></script>
    <script src="js/profile.js?v=<?= time() ?>"></script>
	<script src="js/team-registration.js?v=5"></script>
</body>
</html>
