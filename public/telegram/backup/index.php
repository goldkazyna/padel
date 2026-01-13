<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Padel Tournaments</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="stylesheet" href="style.css?v=<?= time() ?>">

</head>
<body>
    <div id="app">
        <!-- Загрузка -->
		<!-- Запрос телефона для новых -->
		<div id="phone-request" class="screen">
			<div class="phone-request-card">
				<div class="phone-icon">📱</div>
				<h2>Добро пожаловать!</h2>
				<p>Для регистрации на турниры нам нужен ваш номер телефона</p>
				<button id="share-phone-btn" class="action-btn primary">
					Поделиться номером
				</button>
				<button id="skip-phone-btn" class="skip-btn">
					Пропустить
				</button>
			</div>
		</div>

        <!-- Главный экран с табами -->
        <div id="main" class="screen">
            <!-- Header -->
            <div class="header">
                <h1>🎾 Padel Center</h1>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="tournaments">Турниры</button>
                <button class="tab" data-tab="profile">Профиль</button>
            </div>

            <!-- Tournaments Tab -->
            <div id="tournaments-tab" class="tab-content active">
                <div id="tournaments-list" class="tournaments-list">
                    <!-- Турниры загрузятся сюда -->
                </div>
                <div id="no-tournaments" class="empty-state" style="display:none;">
                    <p>😔 Нет открытых турниров</p>
                </div>
            </div>

            <!-- Profile Tab -->
            <div id="profile-tab" class="tab-content">
                <div id="profile-content">
                    <!-- Профиль загрузится сюда -->
                </div>
            </div>
        </div>

        <!-- Tournament Detail Screen -->
        <div id="tournament-detail" class="screen">
            <div class="detail-header">
                <button id="back-btn" class="back-btn">← Назад</button>
            </div>
            <div id="tournament-content">
                <!-- Детали турнира -->
            </div>
        </div>
    </div>

    <script src="app.js?v=<?= time() ?>"></script>
</body>
</html>