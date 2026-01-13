/* ============================================
   PROFILE.JS - Логика страницы профиля
   ============================================ */

let profileData = null;

/**
 * Загрузить профиль
 */
async function loadProfile() {
    const result = await apiProfile();
    profileData = result;
    
    renderProfileHeader();
    renderProfileStats(result.stats);
    renderRatingHistory(result.rating_history);
}

/**
 * Рендер шапки профиля
 */
function renderProfileHeader() {
    const container = document.getElementById('profile-header');
    if (!container || !currentUser) return;
    
    const initial = getInitial(currentUser.name || currentUser.first_name);
    const name = currentUser.name || `${currentUser.first_name || ''} ${currentUser.last_name || ''}`.trim();
    const phone = currentUser.phone ? formatPhone(currentUser.phone) : 'Телефон не указан';
    
    container.innerHTML = `
        <div class="profile-avatar-large">
            <span>${initial}</span>
        </div>
        <h2 class="profile-name">${name}</h2>
        <p class="profile-phone">${phone}</p>
    `;
}

/**
 * Рендер статистики профиля
 */
function renderProfileStats(stats) {
    const container = document.getElementById('profile-stats');
    if (!container || !currentUser) return;
    
    const total = stats?.total || 0;
    const wins = stats?.wins || 0;
    const winRate = total > 0 ? Math.round((wins / total) * 100) : 0;
    
    container.innerHTML = `
        <div class="profile-stat">
            <div class="profile-stat-value">${currentUser.rating || 1000}</div>
            <div class="profile-stat-label">Рейтинг</div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-value">${currentUser.level || '1.0'}</div>
            <div class="profile-stat-label">Уровень</div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-value">${total}</div>
            <div class="profile-stat-label">Матчей</div>
        </div>
        <div class="profile-stat">
            <div class="profile-stat-value">${winRate}%</div>
            <div class="profile-stat-label">Побед</div>
        </div>
    `;
}

/**
 * Рендер истории рейтинга
 */
function renderRatingHistory(history) {
    const container = document.getElementById('rating-history');
    if (!container) return;
    
    if (!history || history.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-text">История рейтинга пуста</div>
            </div>
        `;
        return;
    }
    
    container.innerHTML = `
        <div class="rating-history-list">
            ${history.map(item => `
                <div class="rating-history-item">
                    <div class="rating-history-info">
                        <div class="rating-history-tournament">${item.tournament}</div>
                        <div class="rating-history-date">${item.date || ''}</div>
                    </div>
                    <div class="rating-history-change ${item.change >= 0 ? 'positive' : 'negative'}">
                        ${item.change >= 0 ? '+' : ''}${item.change}
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

/**
 * Форматирование телефона
 */
function formatPhone(phone) {
    if (!phone) return '';
    
    // Убираем всё кроме цифр
    const digits = phone.replace(/\D/g, '');
    
    // Форматируем как +7 XXX XXX XX XX
    if (digits.length === 11) {
        return `+${digits[0]} ${digits.slice(1,4)} ${digits.slice(4,7)} ${digits.slice(7,9)} ${digits.slice(9,11)}`;
    }
    
    return phone;
}
