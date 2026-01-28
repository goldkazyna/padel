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
    const tournaments = result.tournaments || [];
    
    renderTournaments(tournaments);
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
	const isFull = tournament.participants_count >= tournament.max_participants;
	const isTeam = tournament.type === 'team';

	if (tournament.is_registered) {
		const statusText = tournament.registration_status === 'pending' ? 'На модерации' : 'Вы записаны';
		const statusClass = tournament.registration_status === 'pending' ? 'pending' : 'registered';
		footerContent = `<span class="tournament-status ${statusClass}">✓ ${statusText}</span>`;
	} else if (isFull) {
		footerContent = `<span class="tournament-status full">Мест нет</span>`;
	} else if (tournament.can_register !== false) {
		if (isTeam) {
			// Для парных турниров — просто открываем турнир
			footerContent = `<button class="btn-register" onclick="event.stopPropagation(); openTournament(${tournament.id}, true)">Записаться с партнёром</button>`;
		} else {
			footerContent = `<button class="btn-register" onclick="event.stopPropagation(); registerTournament(${tournament.id})">Записаться</button>`;
		}
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
                <div class="tournament-detail ${isFull ? 'full' : ''}">
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
async function openTournament(id, scrollToRegister = false) {
    currentTournamentId = id; // Сохраняем ID
    
    const result = await apiTournament(id);
    
    if (!result.tournament) {
        showAlert('Турнир не найден');
        return;
    }
    
    renderTournamentDetail(result);
    showScreen('tournament-detail');
    
    // Скролл к форме регистрации для парных турниров
    if (scrollToRegister) {
        setTimeout(() => {
            const regForm = document.querySelector('.team-registration');
            if (regForm) {
                regForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }, 100);
    }
    
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
    
    const btn = document.querySelector('[onclick="refreshTournament()"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';
    }
    
    await openTournament(currentTournamentId);
    
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';
    }
}
/**
 * Рендер детальной страницы турнира
 */

function renderTournamentDetail(data) {
    const { tournament, participants, teams, user_team, is_registered, registration_status, can_register } = data;
    const container = document.getElementById('tournament-detail-content');
    if (!container) return;
    
    const date = formatDate(tournament.date);
    const time = formatTime(tournament.time);
    const typeClass = getTournamentTypeClass(tournament.type);
    const typeName = getTournamentTypeName(tournament.type);
    const priceText = tournament.price > 0 ? `${tournament.price.toLocaleString()} ₸` : 'Бесплатно';
    
    const isTeamTournament = tournament.type === 'team';
    
    let actionButton = '';
    
    if (isTeamTournament) {
        // Логика для командного турнира
        if (is_registered && user_team) {
            actionButton = renderTeamRegistrationStatus(tournament, user_team);
            if (registration_status === 'pending') {
                startStatusPolling(tournament.id);
            } else {
                stopStatusPolling();
            }
        } else if (can_register) {
            actionButton = renderTeamRegistrationForm(tournament);
            stopStatusPolling();
        } else {
            const reason = data.block_reason || 'Регистрация недоступна';
            actionButton = `
                <div class="alert alert-danger">⛔ ${reason}</div>
                <button class="btn-blocked" disabled>Регистрация недоступна</button>
            `;
            stopStatusPolling();
        }
    } else {
        // Логика для americano/mexicano
        if (is_registered) {
            if (registration_status === 'pending') {
                actionButton = `
                    <div class="alert alert-warning">
                        ⏳ Ваша заявка на модерации<br><br>
                        💳 Для подтверждения произведите оплату:<br>
                        <a href="https://pay.kaspi.kz/pay/g6b21oa4" target="_blank" class="payment-link">Оплатить через Kaspi</a><br><br>
                        📩 После оплаты сообщите администрации
                    </div>
                    <button class="btn-cancel" onclick="cancelRegistration(${tournament.id})">Отменить регистрацию</button>
                `;
                startStatusPolling(tournament.id);
            } else {
                actionButton = `
                    <div class="alert alert-success">✓ Вы зарегистрированы</div>
                    <button class="btn-cancel" onclick="cancelRegistration(${tournament.id})">Отменить регистрацию</button>
                `;
                stopStatusPolling();
            }
        } else if (can_register) {
            actionButton = `<button class="btn-register" onclick="registerTournament(${tournament.id})">Записаться на турнир</button>`;
            stopStatusPolling();
        } else {
            const reason = data.block_reason || 'Регистрация недоступна';
            actionButton = `
                <div class="alert alert-danger">⛔ ${reason}</div>
                <button class="btn-blocked" disabled>Регистрация недоступна</button>
            `;
            stopStatusPolling();
        }
    }
    
    // Секция участников/команд
    let participantsSection = '';
    
    if (isTeamTournament) {
        const teamsArray = teams || [];
        const approvedTeams = teamsArray.filter(t => t.status === 'approved');
        const pendingTeams = teamsArray.filter(t => t.status === 'pending');
        const maxTeams = tournament.max_participants / 2;
        
        participantsSection = `
            <div class="participants-section">
                <div class="participants-title">Команды (${approvedTeams.length}/${maxTeams})</div>
                ${approvedTeams.length > 0 ? approvedTeams.map(t => `
                    <div class="participant-item team-item">
                        <div class="participant-name">${t.player1} / ${t.player2}</div>
                        <div class="participant-level">Ур. ${t.player1_level}-${t.player2_level}</div>
                    </div>
                `).join('') : '<p style="color: var(--text-muted); font-size: 14px;">Пока никого нет</p>'}
                
                ${pendingTeams.length > 0 ? `
                    <div class="participants-title" style="margin-top: 16px;">На модерации (${pendingTeams.length})</div>
                    ${pendingTeams.map(t => `
                        <div class="participant-item team-item pending">
                            <div class="participant-name">${t.player1} / ${t.player2} <span class="pending-badge">⏳</span></div>
                            <div class="participant-level">Ур. ${t.player1_level}-${t.player2_level}</div>
                        </div>
                    `).join('')}
                ` : ''}
            </div>
        `;
    } else {
        const participantsArray = participants || [];
        participantsSection = `
            <div class="participants-section">
                <div class="participants-title">Участники (${participantsArray.length})</div>
                ${participantsArray.length > 0 ? participantsArray.map(p => `
                    <div class="participant-item ${p.status === 'pending' ? 'pending' : ''}">
                        <div class="participant-name">${p.name} ${p.status === 'pending' ? '<span class="pending-badge">⏳</span>' : ''}</div>
                        <div class="participant-level">Ур. ${p.level}</div>
                    </div>
                `).join('') : '<p style="color: var(--text-muted); font-size: 14px;">Пока никого нет</p>'}
            </div>
        `;
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
                    <div class="tournament-info-value">${typeName}${isTeamTournament ? ' (пары)' : ''}</div>
                </div>
                <div class="tournament-info-item">
                    <div class="tournament-info-label">Мест</div>
                    <div class="tournament-info-value">${tournament.participants_count}/${tournament.max_participants}</div>
                </div>
            </div>
        </div>
        
        ${participantsSection}
        
        <div class="tournament-action">
            ${actionButton}
        </div>
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