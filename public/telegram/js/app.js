/* ============================================
   APP.JS - Инициализация и навигация
   ============================================ */

// Глобальное состояние
let currentUser = null;
let currentScreen = 'home';

/**
 * Инициализация приложения
 */
async function initApp() {
    // Telegram init
    if (!isDev) {
        tg.ready();
        tg.expand();
		tg.disableVerticalSwipes();
		tg.setHeaderColor('#ffffff');  // или '#22c55e' для зелёного
		tg.setBackgroundColor('#ffffff');
    }
   
    // Авторизация
    const authResult = await apiAuth();
    
    if (!authResult.success) {
        showAlert('Ошибка авторизации');
        return;
    }
    
    currentUser = authResult.user;
    
    // Проверяем телефон
    if (authResult.is_new || !currentUser.phone) {
        showScreen('phone-request');
        setupPhoneRequest();
        return;
    }
    
    // Загружаем главную
    await loadHome();
    showScreen('home');
    
    // Настраиваем навигацию
    setupNavigation();
}

/**
 * Настройка навигации
 */
function setupNavigation() {
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', async () => {
            const screen = item.dataset.screen;
            await navigateTo(screen);
        });
    });
}

/**
 * Переход на экран
 */
async function navigateTo(screen) {
    // Обновляем навигацию
    document.querySelectorAll('.nav-item').forEach(nav => {
        nav.classList.toggle('active', nav.dataset.screen === screen);
    });
    
    // Загружаем данные экрана
    switch (screen) {
        case 'home':
            await loadHome();
            break;
        case 'tournaments':
            await loadTournaments();
            break;
        case 'rating':
            await loadRating();
            break;
        case 'profile':
            await loadProfile();
            break;
    }
    
    showScreen(screen);
    currentScreen = screen;
}

/**
 * Показать экран
 */
function showScreen(screenId) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    
    const screen = document.getElementById(screenId + '-screen');
    if (screen) {
        screen.classList.add('active');
    }
}

/**
 * Показать алерт
 */
function showAlert(message) {
    if (isDev) {
        alert(message);
    } else {
        tg.showAlert(message);
    }
}

/**
 * Показать подтверждение
 */
function showConfirm(message, callback) {
    if (isDev) {
        callback(confirm(message));
    } else {
        tg.showConfirm(message, callback);
    }
}

/**
 * Настройка запроса телефона
 */
function setupPhoneRequest() {
    document.getElementById('share-phone-btn')?.addEventListener('click', requestPhone);
    document.getElementById('skip-phone-btn')?.addEventListener('click', skipPhone);
}

/**
 * Запрос телефона
 */
function requestPhone() {
    if (isDev) {
        const phone = prompt('Введите телефон:');
        if (phone) savePhone(phone);
        return;
    }
    
    tg.requestContact((sent) => {
        if (sent) {
            showAlert('Спасибо! Номер сохранён');
            setTimeout(async () => {
                const result = await apiAuth();
                if (result.success) {
                    currentUser = result.user;
                }
                await loadHome();
                showScreen('home');
                setupNavigation();
            }, 1500);
        } else {
            showAlert('Вы отменили отправку');
        }
    });
}

/**
 * Сохранить телефон
 */
async function savePhone(phone) {
    const result = await apiSavePhone(phone);
    if (result.success) {
        currentUser = result.user;
        showAlert('Номер сохранён!');
        await loadHome();
        showScreen('home');
        setupNavigation();
    }
}

/**
 * Пропустить запрос телефона
 */
async function skipPhone() {
    await loadHome();
    showScreen('home');
    setupNavigation();
}

/**
 * Форматирование даты
 */
/**
 * Форматирование даты
 */
function formatDate(dateStr) {
    if (!dateStr) return { day: '?', month: '', full: '?' };
    
    let date;
    
    // Если формат DD.MM.YYYY
    if (typeof dateStr === 'string' && dateStr.includes('.')) {
        const parts = dateStr.split('.');
        if (parts.length === 3) {
            // Создаём дату как YYYY-MM-DD
            date = new Date(parts[2], parts[1] - 1, parts[0]);
        }
    } else {
        date = new Date(dateStr);
    }
    
    // Проверяем что дата валидна
    if (isNaN(date.getTime())) {
        return { day: '?', month: '', full: dateStr || '?' };
    }
    
    const months = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    return {
        day: date.getDate(),
        month: months[date.getMonth()],
        full: `${date.getDate()} ${months[date.getMonth()]}`
    };
}

/**
 * Форматирование времени
 */
function formatTime(timeStr) {
    return timeStr ? timeStr.slice(0, 5) : '';
}

/**
 * Получить первую букву
 */
function getInitial(name) {
    if (!name) return '?';
    return name.charAt(0).toUpperCase();
}

/**
 * Тип турнира badge class
 */
function getTournamentTypeClass(type) {
    const types = {
        'americano': 'americano',
        'mexicano': 'mexicano',
        'team': 'team',
        'classic': 'classic'
    };
    return types[type] || 'classic';
}

/**
 * Тип турнира название
 */
function getTournamentTypeName(type) {
    const types = {
        'americano': 'Американо',
        'mexicano': 'Мексикано',
        'team': 'Командный',
        'classic': 'Классический'
    };
    return types[type] || type;
}
/**
 * Обновить главную страницу
 */
async function refreshHome() {
    await loadHome();
}
// Запуск приложения
document.addEventListener('DOMContentLoaded', initApp);
