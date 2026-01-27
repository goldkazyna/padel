/* ============================================
   HOME.JS - Логика главной страницы
   ============================================ */

/**
 * Загрузить главную страницу
 */
async function loadHome() {
    renderWelcome();
    await renderQuickStats();
    await renderNextTournament();
    // TODO: renderRecentMatches() - когда будет API
}

/**
 * Рендер приветствия
 */
function renderWelcome() {
    const container = document.getElementById('welcome-section');
    if (!container || !currentUser) return;
    
    const initial = getInitial(currentUser.name || currentUser.first_name);
    const name = currentUser.name || currentUser.first_name || 'Игрок';
    
    container.innerHTML = `
        <div class="welcome-text">
            <span class="welcome-label">Добро пожаловать</span>
            <h1 class="welcome-name">${name}</h1>
        </div>
        <div class="avatar avatar-md">
            <span>${initial}</span>
        </div>
    `;
}

/**
 * Рендер быстрой статистики
 */
async function renderQuickStats() {
    const container = document.getElementById('quick-stats');
    if (!container || !currentUser) return;
    
    // Получаем профиль для статистики
    const profileData = await apiProfile();
    const rank = profileData.rank || '-';
    
    container.innerHTML = `
        <div class="quick-stat">
            <div class="quick-stat-value">-</div>
            <div class="quick-stat-label">Рейтинг</div>
        </div>
        <div class="quick-stat">
            <div class="quick-stat-value">${currentUser.level || '1.0'}</div>
            <div class="quick-stat-label">Уровень</div>
        </div>
        <div class="quick-stat">
            <div class="quick-stat-value">#${rank}</div>
            <div class="quick-stat-label">Позиция</div>
        </div>
    `;
}

/**
 * Рендер ближайшего турнира
 */
/**
 * Рендер турниров
 */
async function renderNextTournament() {
    const container = document.getElementById('next-tournament');
    if (!container) return;
    
    try {
        const result = await apiTournaments();
        const tournaments = result.tournaments || [];
        
        if (tournaments.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">🎾</div>
                    <div class="empty-state-title">Нет турниров</div>
                    <div class="empty-state-text">Скоро появятся новые турниры</div>
                </div>
            `;
            return;
        }
        
        container.innerHTML = tournaments.map(tournament => {
            const date = formatDate(tournament.date);
            const time = formatTime(tournament.time);
            const isFull = tournament.participants_count >= tournament.max_participants;
            
            return `
                <div class="next-tournament-card" onclick="openTournament(${tournament.id})">
                    <div class="next-tournament-date">
                        <span class="date-day">${date.day}</span>
                        <span class="date-month">${date.month}</span>
                    </div>
                    <div class="next-tournament-info">
                        <h3 class="next-tournament-name">${tournament.name}</h3>
                        <p class="next-tournament-club">${tournament.club || ''}</p>
                        <div class="next-tournament-meta">
                            <span class="meta-item">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 6v6l4 2"/>
                                </svg>
                                ${time}
                            </span>
                            <span class="meta-item ${isFull ? 'full' : ''}">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                </svg>
                                ${tournament.participants_count}/${tournament.max_participants}
                            </span>
                            <span class="meta-item">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                </svg>
                                ${tournament.min_level}-${tournament.max_level}
                            </span>
                        </div>
                    </div>
                    ${tournament.is_registered ? `
                        <div class="next-tournament-status registered">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 6L9 17l-5-5"/>
                            </svg>
                        </div>
                    ` : isFull ? `
                        <div class="next-tournament-status full">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="15" y1="9" x2="9" y2="15"/>
                                <line x1="9" y1="9" x2="15" y2="15"/>
                            </svg>
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');
    } catch (error) {
        console.error('renderTournaments error:', error);
        container.innerHTML = `<div class="empty-state"><div class="empty-state-text">Ошибка загрузки</div></div>`;
    }
}

/**
 * Рендер последних матчей
 */
function renderRecentMatches(matches) {
    const container = document.getElementById('recent-matches');
    if (!container) return;
    
    if (!matches || matches.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-text">Пока нет сыгранных матчей</div>
            </div>
        `;
        return;
    }
    
    container.innerHTML = `
        <div class="matches-list">
            ${matches.map(match => `
                <div class="match-item ${match.is_win ? 'win' : 'loss'}">
                    <div class="match-result">${match.is_win ? 'W' : 'L'}</div>
                    <div class="match-info">
                        <div class="match-teams">${match.teams}</div>
                        <div class="match-tournament">${match.tournament}</div>
                    </div>
                    <div class="match-score">${match.score}</div>
                </div>
            `).join('')}
        </div>
    `;
}
