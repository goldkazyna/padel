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
    renderRatingHistory(); // Без параметра - берёт из profileData
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
        <div class="profile-name-row">
            <h2 class="profile-name">${name}</h2>
            <button class="edit-btn" onclick="openEditName()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
            </button>
        </div>
        <p class="profile-phone">${phone}</p>
    `;
}

/**
 * Открыть редактирование имени
 */
function openEditName() {
    const currentName = currentUser.name || '';
    
    if (isDev) {
        const newName = prompt('Введите имя:', currentName);
        if (newName && newName !== currentName) {
            saveName(newName);
        }
        return;
    }
    
    // Показываем модалку
    const modal = document.getElementById('edit-name-modal');
    const input = document.getElementById('edit-name-input');
    if (modal && input) {
        input.value = currentName;
        modal.classList.add('active');
        input.focus();
    }
}

/**
 * Закрыть модалку
 */
function closeEditName() {
    const modal = document.getElementById('edit-name-modal');
    if (modal) {
        modal.classList.remove('active');
    }
}

/**
 * Сохранить имя
 */
async function saveName(name) {
    const result = await apiSaveName(name);
    
    if (result.success) {
        currentUser = result.user;
        showAlert('Имя сохранено!');
        renderProfileHeader();
        renderWelcome(); // Обновляем главную тоже
        closeEditName();
    } else {
        showAlert(result.error || 'Ошибка сохранения');
    }
}

/**
 * Сабмит формы имени
 */
function submitEditName() {
    const input = document.getElementById('edit-name-input');
    if (input && input.value.trim()) {
        saveName(input.value.trim());
    }
}

/**
 * Рендер статистики профиля
 */
function renderProfileStats(stats) {
    const container = document.getElementById('profile-stats');
    if (!container || !currentUser) return;
    
    const total = stats?.total || 0;
    const wins = stats?.won || stats?.wins || 0;
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
 * Рендер истории турниров с матчами
 */

function renderRatingHistory() {
    const container = document.getElementById('rating-history');
    if (!container) return;
    
    const tournaments = profileData?.tournament_history || [];
    
    if (!tournaments || tournaments.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-text">История турниров пуста</div>
            </div>
        `;
        return;
    }
    
    container.innerHTML = tournaments.map(tournament => renderTournamentItem(tournament)).join('');
}

/**
 * Рендер турнира
 */
function renderTournamentItem(tournament) {
    const changeClass = tournament.change >= 0 ? 'positive' : 'negative';
    const changeText = tournament.change >= 0 ? `+${tournament.change}` : tournament.change;
    
    const matchesHtml = tournament.matches && tournament.matches.length > 0
        ? tournament.matches.map(match => renderMatchItem(match)).join('')
        : '<div class="empty-state"><div class="empty-state-text">Нет данных о матчах</div></div>';
    
    return `
        <div class="th-tournament-item">
            <div class="th-tournament-header" onclick="toggleTournament(this)">
                <div class="th-tournament-info">
                    <span class="th-tournament-name">${tournament.name}</span>
                    <span class="th-tournament-date">${tournament.date}</span>
                </div>
                <div class="th-tournament-right">
                    <span class="th-tournament-points ${changeClass}">${changeText}</span>
                    <div class="th-tournament-arrow">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="th-matches-container">
                <div class="th-matches-list">
                    ${matchesHtml}
                </div>
            </div>
        </div>
    `;
}

/**
 * Рендер матча
 */
function renderMatchItem(match) {
    const resultIcon = match.won
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    
    const resultClass = match.won ? 'win' : 'lose';
    
    return `
        <div class="th-match-item">
            <div class="th-match-header">
                <span class="th-match-round">${match.round}</span>
                <div class="th-match-total-score">
                    <span>${match.my_score} : ${match.opp_score}</span>
                    <div class="th-match-result-icon ${resultClass}">
                        ${resultIcon}
                    </div>
                </div>
            </div>
            <div class="th-match-body">
                <!-- Моя команда -->
                <div class="th-team-row your-team">
                    <div class="th-team-players">
                        ${renderPlayerLine(match.me, true)}
                        ${match.partner ? renderPlayerLine(match.partner, false) : ''}
                    </div>
                    <span class="th-team-score ${match.won ? 'win' : 'lose'}">${match.my_score}</span>
                </div>
                
                <div class="th-vs-divider">
                    <span class="th-vs-text">VS</span>
                </div>
                
                <!-- Соперники -->
                <div class="th-team-row">
                    <div class="th-team-players">
                        ${match.opponent1 ? renderPlayerLine(match.opponent1, false, true) : ''}
                        ${match.opponent2 ? renderPlayerLine(match.opponent2, false, true) : ''}
                    </div>
                    <span class="th-team-score ${match.won ? 'lose' : 'win'}">${match.opp_score}</span>
                </div>
            </div>
        </div>
    `;
}

/**
 * Рендер строки игрока
 */
function renderPlayerLine(player, isMe = false, isOpponent = false) {
    if (!player) return '';
    
    const initial = getInitial(player.name);
    const avatarClass = isOpponent ? 'th-player-avatar opponent' : 'th-player-avatar';
    const nameClass = isMe ? 'th-player-name you' : 'th-player-name';
    const youBadge = isMe ? '<span class="th-you-badge">ВЫ</span>' : '';
    
    return `
        <div class="th-player-line">
            <div class="${avatarClass}">${initial}</div>
            <div class="th-player-data">
                <span class="${nameClass}">${player.name}${youBadge}</span>
                <div class="th-player-meta">
                    <span class="th-player-level">${player.level}</span>
                    <span>•</span>
                    <span>${player.rating}</span>
                </div>
            </div>
        </div>
    `;
}

/**
 * Раскрыть/закрыть турнир
 */
function toggleTournament(header) {
    const item = header.closest('.th-tournament-item');
    item.classList.toggle('open');
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
