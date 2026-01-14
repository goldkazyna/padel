/* ============================================
   API.JS - Работа с API
   ============================================ */

const API_BASE = '/api/tg';

// Telegram WebApp
const tg = window.Telegram.WebApp;

// DEV MODE detection
const isDev = !tg.initData || tg.initData === '';

/**
 * Базовый API запрос
 */
async function api(endpoint, method = 'GET', body = null) {
    const headers = {
        'Content-Type': 'application/json',
    };
    
    if (isDev) {
        headers['X-Dev-Mode'] = 'true';
        headers['X-Dev-User-Id'] = '123456789';
    } else {
        headers['X-Telegram-Init-Data'] = tg.initData;
    }
    
    const options = { method, headers };
    
    if (body) {
        options.body = JSON.stringify(body);
    }
    
    try {
        const response = await fetch(API_BASE + endpoint, options);
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        return { success: false, error: error.message };
    }
}

/**
 * Авторизация
 */
async function apiAuth() {
    return api('/auth', 'POST');
}

/**
 * Получить профиль
 */
async function apiProfile() {
    return api('/profile');
}

/**
 * Получить список турниров
 */
async function apiTournaments() {
    return api('/tournaments');
}

/**
 * Получить детали турнира
 */
async function apiTournament(id) {
    return api(`/tournaments/${id}`);
}

/**
 * Регистрация на турнир
 */
async function apiRegister(id) {
    return api(`/tournaments/${id}/register`, 'POST');
}

/**
 * Отмена регистрации
 */
async function apiCancelRegistration(id) {
    return api(`/tournaments/${id}/cancel`, 'POST');
}

/**
 * Получить рейтинг
 */
async function apiRating() {
    return api('/rating');
}

/**
 * Сохранить телефон
 */
async function apiSavePhone(phone) {
    return api('/profile/phone', 'POST', { phone });
}
/**
 * Сохранить имя
 */
async function apiSaveName(name) {
    return api('/profile/name', 'POST', { name });
}
/**
 * Получить рейтинг
 */
async function apiRating(page = 1) {
    return api(`/rating?page=${page}`);
}
