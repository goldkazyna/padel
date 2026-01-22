/* ============================================
   TOURNAMENTS.JS - Логика страницы турниров
   ============================================ */

let allTournaments = [];
let currentFilter = 'all';

let statusPolling = null;

let currentTournamentId = null;
/**
 * Загрузить турниры
 */
async function loadTournaments() {
    const result = await apiTournaments();
    allTournaments = result.tournaments || [];
    
    setupTournamentFilters();
    renderTournaments(allTournaments);
}

/**
 * Настройка фильтров
 */
function setupTournamentFilters() {
    document.querySelectorAll('#tournaments-screen .filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            // Обновляем активный фильтр
            document.querySelectorAll('#tournaments-screen .filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            currentFilter = btn.dataset.filter;
            filterTournaments();
        });
    });
}

/**
 * Фильтрация турниров
 */
function filterTournaments() {
    let filtered = allTournaments;
    
    switch (currentFilter) {
        case 'open':
            filtered = allTournaments.filter(t => t.status === 'open' && !t.is_registered);
            break;
        case 'my':
            filtered = allTournaments.filter(t => t.is_registered);
            break;
        default:
            filtered = allTournaments;
    }
    
    renderTournaments(filtered);
}

/**
 * Рендер списка турниров
 */
function renderTournaments(tournaments) {
    const container = document.getElementById('tournaments-list');
    if (!container) return;
    
    if (tournaments.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">🎾</div>
                <div class="empty-state-title">Нет турниров</div>
                <div class="empty-state-text">
                    ${currentFilter === 'my' ? 'Вы пока не записаны на турниры' : 'Скоро появятся новые турниры'}
                </div>
            </div>
        `;
        return;
    }
    
    container.innerHTML = tournaments.map(t => renderTournamentCard(t)).join('');
}

/**
 * Рендер карточки турнира
 */
function renderTournamentCard(tournament) {
    const date = formatDate(tournament.date);
    const time = formatTime(tournament.time);
    const typeClass = getTournamentTypeClass(tournament.type);
    const typeName = getTournamentTypeName(tournament.type);
    
    const priceText = tournament.price > 0 ? `${tournament.price.toLocaleString()} ₸` : 'Бесплатно';
    
    let footerContent = '';
    if (tournament.is_registered) {
        const statusText = tournament.registration_status === 'pending' ? 'На модерации' : 'Вы записаны';
        const statusClass = tournament.registration_status === 'pending' ? 'pending' : 'registered';
        footerContent = `<span class="tournament-status ${statusClass}">✓ ${statusText}</span>`;
    } else if (tournament.can_register !== false) {
        footerContent = `<button class="btn-register" onclick="event.stopPropagation(); registerTournament(${tournament.id})">Записаться</button>`;
    } else {
        footerContent = `<span class="tournament-status">Регистрация закрыта</span>`;
    }
    
    return `
        <div class="tournament-card" onclick="openTournament(${tournament.id})">
            <div class="tournament-card-header">
                <div class="tournament-type ${typeClass}">${typeName}</div>
                <div class="tournament-price">${priceText}</div>
            </div>
            <h3 class="tournament-name">${tournament.name}</h3>
            <p class="tournament-club">${tournament.club || ''}</p>
            <div class="tournament-details">
                <div class="tournament-detail">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <span>${date.full}, ${time}</span>
                </div>
                <div class="tournament-detail">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <span>${tournament.participants_count}/${tournament.max_participants} участников</span>
                </div>
                <div class="tournament-detail">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                    <span>Уровень ${tournament.min_level} - ${tournament.max_level}</span>
                </div>
            </div>
            <div class="tournament-card-footer">
                ${footerContent}
            </div>
        </div>
    `;
}

/**
 * Открыть турнир
 */
async function openTournament(id) {
    currentTournamentId = id; // Сохраняем ID
    
    const result = await apiTournament(id);
    
    if (!result.tournament) {
        showAlert('Турнир не найден');
        return;
    }
    
    renderTournamentDetail(result);
    showScreen('tournament-detail');
    
    // Back button
    if (!isDev) {
        tg.BackButton.show();
        tg.BackButton.onClick(() => {
            showScreen('tournaments');
            tg.BackButton.hide();
        });
    }
}

/**
 * Обновить текущий турнир
 */
async function refreshTournament() {
    if (!currentTournamentId) return;
    
    // Дизейблим кнопку на время загрузки
    const btn = document.querySelector('[onclick="refreshTournament()"]');
    if (btn) {
        btn.disabled = true;
        btn.textContent = '⏳';
    }
    
    await openTournament(currentTournamentId);
    
    // Возвращаем кнопку
    if (btn) {
        btn.disabled = false;
        btn.textContent = '⟳ Обновить';
    }
}
/**
 * Рендер детальной страницы турнира
 */
function renderTournamentDetail(data) {
    const { tournament, participants, is_registered, registration_status, can_register } = data;
    const container = document.getElementById('tournament-detail-content');
    if (!container) return;
    
    const date = formatDate(tournament.date);
    const time = formatTime(tournament.time);
    const typeClass = getTournamentTypeClass(tournament.type);
    const typeName = getTournamentTypeName(tournament.type);
    const priceText = tournament.price > 0 ? `${tournament.price.toLocaleString()} ₸` : 'Бесплатно';
    
    let actionButton = '';
    if (is_registered) {
        if (registration_status === 'pending') {
            actionButton = `
                <div class="alert alert-warning">⏳ Ваша заявка на модерации</div>
                <button class="btn-cancel" onclick="cancelRegistration(${tournament.id})">Отменить регистрацию</button>
            `;
            startStatusPolling(tournament.id);
        } else {
            actionButton = `
                <div class="alert alert-success">✓ Вы зарегистрированы (status: ${registration_status})</div>
                <button class="btn-cancel" onclick="cancelRegistration(${tournament.id})">Отменить регистрацию</button>
            `;
            stopStatusPolling();
        }
    } else if (can_register) {
        actionButton = `<button class="btn-register" onclick="registerTournament(${tournament.id})">Записаться на турнир</button>`;
        stopStatusPolling();
    } else {
        actionButton = `<button class="btn-register" disabled>Регистрация недоступна</button>`;
        stopStatusPolling();
    }
    
    container.innerHTML = `
        <div class="tournament-detail-card">
            <div class="tournament-detail-header">
                <div class="tournament-type ${typeClass} tournament-detail-type">${typeName}</div>
                <h2 class="tournament-detail-title">${tournament.name}</h2>
                <p class="tournament-detail-club">📍 ${tournament.club || ''}</p>
            </div>
            
            <div class="tournament-info-grid">
                <div class="tournament-info-item">
                    <div class="tournament-info-label">Дата</div>
                    <div class="tournament-info-value">${date.full}</div>
                </div>
                <div class="tournament-info-item">
                    <div class="tournament-info-label">Время</div>
                    <div class="tournament-info-value">${time}</div>
                </div>
                <div class="tournament-info-item">
                    <div class="tournament-info-label">Уровень</div>
                    <div class="tournament-info-value">${tournament.min_level} - ${tournament.max_level}</div>
                </div>
                <div class="tournament-info-item">
                    <div class="tournament-info-label">Стоимость</div>
                    <div class="tournament-info-value">${priceText}</div>
                </div>
                <div class="tournament-info-item">
                    <div class="tournament-info-label">Формат</div>
                    <div class="tournament-info-value">${typeName}</div>
                </div>
                <div class="tournament-info-item">
                    <div class="tournament-info-label">Мест</div>
                    <div class="tournament-info-value">${tournament.participants_count}/${tournament.max_participants}</div>
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
            `).join('') : '<p style="color: var(--text-muted); font-size: 14px;">Пока никого нет</p>'}
        </div>
        
        ${actionButton}
    `;
}

/**
 * Регистрация на турнир
 */
async function registerTournament(id) {
    const result = await apiRegister(id);
    
    if (result.success) {
        showAlert(result.message || 'Вы успешно записаны!');
        await loadTournaments();
        openTournament(id);
    } else {
        showAlert(result.error || 'Ошибка регистрации');
        
        // Если мест нет — обновляем страницу
        if (result.error && result.error.includes('мест')) {
            setTimeout(() => {
                refreshTournament();
            }, 1500);
        }
    }
}

/**
 * Отмена регистрации
 */
function cancelRegistration(id) {
    showConfirm('Отменить регистрацию?', async (confirmed) => {
        if (!confirmed) return;
        
        const result = await apiCancelRegistration(id);
        
        if (result.success) {
            showAlert(result.message || 'Регистрация отменена');
            await loadTournaments();
            openTournament(id);
        } else {
            showAlert(result.error || 'Ошибка отмены');
        }
    });
}

/**
 * Назад из деталей турнира
 */
function backFromTournament() {
	currentTournamentId = null; // Сбрасываем ID
    stopStatusPolling();
    navigateTo('tournaments');
}
/**
 * Запустить polling статуса
 */
function startStatusPolling(tournamentId) {
    // Останавливаем предыдущий если был
    stopStatusPolling();
    
    statusPolling = setInterval(async () => {
        const result = await apiCheckStatus(tournamentId);
        
        if (result.status === 'registered') {
            // Статус изменился — обновляем и останавливаем polling
            stopStatusPolling();
            showAlert('🎉 Ваша заявка одобрена!');
            openTournament(tournamentId);
        } else if (result.status !== 'pending') {
            // Статус не pending — останавливаем polling
            stopStatusPolling();
        }
    }, 30000); // каждые 30 секунд
}

/**
 * Остановить polling
 */
function stopStatusPolling() {
    if (statusPolling) {
        clearInterval(statusPolling);
        statusPolling = null;
    }
}

/**
 * Обновить список турниров
 */
async function refreshTournaments() {
    await loadTournaments();
}