// Telegram WebApp
const tg = window.Telegram.WebApp;

// DEV MODE detection
const isDev = !tg.initData || tg.initData === '';

if (!isDev) {
    tg.ready();
    tg.expand();

    // Применяем тему Telegram
    document.documentElement.style.setProperty('--tg-theme-bg-color', tg.themeParams.bg_color || '#1a1a1a');
    document.documentElement.style.setProperty('--tg-theme-text-color', tg.themeParams.text_color || '#ffffff');
    document.documentElement.style.setProperty('--tg-theme-hint-color', tg.themeParams.hint_color || '#9ca3af');
    document.documentElement.style.setProperty('--tg-theme-button-color', tg.themeParams.button_color || '#22c55e');
    document.documentElement.style.setProperty('--tg-theme-button-text-color', tg.themeParams.button_text_color || '#ffffff');
    document.documentElement.style.setProperty('--tg-theme-secondary-bg-color', tg.themeParams.secondary_bg_color || '#2d2d2d');
}

// API Base URL
const API_BASE = '/api/tg';

// State
let currentUser = null;
let tournaments = [];

// Alert helper (works in dev mode too)
function showAlert(message) {
    if (isDev) {
        alert(message);
    } else {
        tg.showAlert(message);
    }
}

// Confirm helper
function showConfirm(message, callback) {
    if (isDev) {
        callback(confirm(message));
    } else {
        tg.showConfirm(message, callback);
    }
}

// API Helper
async function api(endpoint, method = 'GET', body = null) {
    const headers = {
        'Content-Type': 'application/json',
    };
    
    if (isDev) {
        headers['X-Dev-Mode'] = 'true';
        headers['X-Dev-User-Id'] = '123456789'; // Замени на свой telegram_id из базы!
    } else {
        headers['X-Telegram-Init-Data'] = tg.initData;
    }
    
    const options = { method, headers };
    
    if (body) {
        options.body = JSON.stringify(body);
    }
    
    const response = await fetch(API_BASE + endpoint, options);
    return response.json();
}

// Screen Management
function showScreen(screenId) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.getElementById(screenId).classList.add('active');
}

// Tab Management
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        tab.classList.add('active');
        document.getElementById(tab.dataset.tab + '-tab').classList.add('active');
        
        if (tab.dataset.tab === 'profile') {
            loadProfile();
        }
    });
});

// Back Button
document.getElementById('back-btn').addEventListener('click', () => {
    showScreen('main');
    if (!isDev) tg.BackButton.hide();
});

async function init() {
    try {
        console.log('Starting init...');
        console.log('isDev:', isDev);
        console.log('initData:', tg.initData ? 'exists' : 'empty');
        
        const authResult = await api('/auth', 'POST');
        console.log('Auth result:', authResult);
        
        // DEBUG: показываем на экране
        if (authResult.error) {
            document.getElementById('loading').innerHTML = `
                <p style="color: red;">Ошибка: ${authResult.error}</p>
                <p style="font-size: 12px; margin-top: 10px;">isDev: ${isDev}</p>
                <p style="font-size: 12px;">initData: ${tg.initData ? 'есть' : 'нет'}</p>
            `;
            return;
        }
        
        if (authResult.success) {
            currentUser = authResult.user;
            
            if (authResult.is_new) {
                showAlert('Добро пожаловать в Padel Center! 🎾');
            }
            
            await loadTournaments();
            showScreen('main');
        } else {
            showAlert('Ошибка авторизации: ' + (authResult.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Init error:', error);
        // DEBUG: показываем ошибку на экране
        document.getElementById('loading').innerHTML = `
            <p style="color: red;">Catch Error: ${error.message}</p>
            <p style="font-size: 12px; margin-top: 10px;">isDev: ${isDev}</p>
        `;
    }
}
// Load Tournaments
async function loadTournaments() {
    try {
        const result = await api('/tournaments');
        tournaments = result.tournaments || [];
        renderTournaments();
    } catch (error) {
        console.error('Load tournaments error:', error);
    }
}

// Render Tournaments
function renderTournaments() {
    const container = document.getElementById('tournaments-list');
    const emptyState = document.getElementById('no-tournaments');
    
    if (tournaments.length === 0) {
        container.style.display = 'none';
        emptyState.style.display = 'block';
        return;
    }
    
    container.style.display = 'block';
    emptyState.style.display = 'none';
    
    container.innerHTML = tournaments.map(t => `
        <div class="tournament-card" onclick="openTournament(${t.id})">
            <div class="tournament-card__header">
                <div>
                    <div class="tournament-card__name">${t.name}</div>
                    <div class="tournament-card__club">${t.club}</div>
                </div>
                <div class="tournament-card__type">${t.type_name}</div>
            </div>
            <div class="tournament-card__info">
                <div class="tournament-card__info-item">
                    📅 <span>${t.date}</span>
                </div>
                <div class="tournament-card__info-item">
                    🕐 <span>${t.time}</span>
                </div>
                <div class="tournament-card__info-item">
                    📊 <span>${t.min_level}-${t.max_level}</span>
                </div>
                <div class="tournament-card__info-item">
                    💰 <span>${t.price > 0 ? t.price + '₸' : 'Бесплатно'}</span>
                </div>
            </div>
            <div class="tournament-card__footer">
                <div class="tournament-card__participants">
                    👥 ${t.participants_count}/${t.max_participants}
                </div>
                ${t.is_registered ? `
                    <div class="tournament-card__status ${t.registration_status}">
                        ${t.registration_status === 'registered' ? '✓ Записан' : '⏳ На модерации'}
                    </div>
                ` : ''}
            </div>
        </div>
    `).join('');
}

// Open Tournament
async function openTournament(id) {
    try {
        const result = await api(`/tournaments/${id}`);
        
        if (result.tournament) {
            renderTournamentDetail(result);
            showScreen('tournament-detail');
            if (!isDev) {
                tg.BackButton.show();
                tg.BackButton.onClick(() => {
                    showScreen('main');
                    tg.BackButton.hide();
                });
            }
        }
    } catch (error) {
        console.error('Load tournament error:', error);
        showAlert('Ошибка загрузки турнира');
    }
}

// Render Tournament Detail
function renderTournamentDetail(data) {
    const { tournament, participants, is_registered, registration_status, can_register } = data;
    const container = document.getElementById('tournament-content');
    
    let actionButton = '';
    
    if (is_registered) {
        if (registration_status === 'pending') {
            actionButton = `
                <div class="alert warning">⏳ Ваша заявка на модерации</div>
                <button class="action-btn danger" onclick="cancelRegistration(${tournament.id})">
                    Отменить заявку
                </button>
            `;
        } else {
            actionButton = `
                <div class="alert success">✓ Вы зарегистрированы!</div>
                <button class="action-btn danger" onclick="cancelRegistration(${tournament.id})">
                    Отменить регистрацию
                </button>
            `;
        }
    } else if (can_register) {
        actionButton = `
            <button class="action-btn primary" onclick="registerTournament(${tournament.id})">
                Записаться на турнир
            </button>
        `;
    } else {
        actionButton = `
            <button class="action-btn primary" disabled>
                Регистрация недоступна
            </button>
        `;
    }
    
    container.innerHTML = `
        <div class="detail-card">
            <div class="detail-title">${tournament.name}</div>
            <div class="detail-club">📍 ${tournament.club}</div>
            ${tournament.address ? `<div class="detail-club">${tournament.address}</div>` : ''}
            
            <div class="detail-info-grid">
                <div class="detail-info-item">
                    <div class="detail-info-label">Дата</div>
                    <div class="detail-info-value">${tournament.date}</div>
                </div>
                <div class="detail-info-item">
                    <div class="detail-info-label">Время</div>
                    <div class="detail-info-value">${tournament.time}</div>
                </div>
                <div class="detail-info-item">
                    <div class="detail-info-label">Уровень</div>
                    <div class="detail-info-value">${tournament.min_level} - ${tournament.max_level}</div>
                </div>
                <div class="detail-info-item">
                    <div class="detail-info-label">Стоимость</div>
                    <div class="detail-info-value">${tournament.price > 0 ? tournament.price + '₸' : 'Бесплатно'}</div>
                </div>
                <div class="detail-info-item">
                    <div class="detail-info-label">Формат</div>
                    <div class="detail-info-value">${tournament.type_name}</div>
                </div>
                <div class="detail-info-item">
                    <div class="detail-info-label">Мест</div>
                    <div class="detail-info-value">${tournament.participants_count}/${tournament.max_participants}</div>
                </div>
            </div>
        </div>
        
        <div class="participants-section">
            <div class="participants-title">Участники (${participants.length})</div>
            ${participants.length > 0 ? participants.map(p => `
                <div class="participant-item">
                    <div class="participant-name">${p.name}</div>
                    <div class="participant-level">Ур. ${p.level}</div>
                </div>
            `).join('') : '<p style="color: var(--tg-theme-hint-color); font-size: 14px;">Пока никого нет</p>'}
        </div>
        
        ${actionButton}
    `;
}

// Register Tournament
async function registerTournament(id) {
    try {
        const result = await api(`/tournaments/${id}/register`, 'POST');
        
        if (result.success) {
            showAlert(result.message);
            await loadTournaments();
            openTournament(id);
        } else {
            showAlert(result.error || 'Ошибка регистрации');
        }
    } catch (error) {
        console.error('Register error:', error);
        showAlert('Ошибка регистрации');
    }
}

// Cancel Registration
async function cancelRegistration(id) {
    showConfirm('Отменить регистрацию?', async (confirmed) => {
        if (!confirmed) return;
        
        try {
            const result = await api(`/tournaments/${id}/cancel`, 'POST');
            
            if (result.success) {
                showAlert(result.message);
                await loadTournaments();
                openTournament(id);
            } else {
                showAlert(result.error || 'Ошибка отмены');
            }
        } catch (error) {
            console.error('Cancel error:', error);
            showAlert('Ошибка отмены регистрации');
        }
    });
}

// Load Profile
async function loadProfile() {
    try {
        const result = await api('/profile');
        
        if (result.user) {
            renderProfile(result);
        }
    } catch (error) {
        console.error('Load profile error:', error);
    }
}

// Render Profile
function renderProfile(data) {
    const { user, stats, rating_history, rank } = data;
    const container = document.getElementById('profile-content');
    
    const initial = user.first_name ? user.first_name.charAt(0).toUpperCase() : '?';
    
    container.innerHTML = `
        <div class="profile-card">
            <div class="profile-avatar">${initial}</div>
            <div class="profile-name">${user.name}</div>
            <div class="profile-level">Уровень ${user.level} • #${rank} в рейтинге</div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">${user.rating}</div>
                <div class="stat-label">Рейтинг</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${stats.total || 0}</div>
                <div class="stat-label">Матчей</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${stats.wins || 0}</div>
                <div class="stat-label">Побед</div>
            </div>
        </div>
        
        ${rating_history.length > 0 ? `
            <div class="rating-history">
                <div class="rating-history__title">История рейтинга</div>
                ${rating_history.map(h => `
                    <div class="rating-history__item">
                        <div class="rating-history__tournament">${h.tournament}</div>
                        <div class="rating-history__change ${h.change >= 0 ? 'positive' : 'negative'}">
                            ${h.change >= 0 ? '+' : ''}${h.change}
                        </div>
                    </div>
                `).join('')}
            </div>
        ` : ''}
    `;
}

// Start
init();