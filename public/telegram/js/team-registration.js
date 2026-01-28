/* ============================================
   TEAM-REGISTRATION.JS - Регистрация пар
   ============================================ */

let selectedPartner = null;

/**
 * API: Поиск партнера
 */
 function showToast(message) {
    alert(message);
}
async function apiSearchPartner(phone) {
    console.log('Calling API with phone:', phone);
    const result = await api('/team/search-partner', 'POST', { phone });
    console.log('API result:', result);
    return result;
}

/**
 * API: Регистрация пары
 */
async function apiRegisterTeam(tournamentId, partnerId) {
    return api('/team/register', 'POST', { 
        tournament_id: tournamentId, 
        partner_id: partnerId 
    });
}

/**
 * API: Отмена регистрации пары
 */
async function apiCancelTeam(tournamentId) {
    return api('/team/cancel', 'POST', { tournament_id: tournamentId });
}

/**
 * Рендер формы регистрации для командного турнира
 */
function renderTeamRegistrationForm(tournament) {
    return `
        <div class="team-registration">
            <div class="team-reg-title">Регистрация пары</div>
            
            <div class="partner-search">
                <label class="partner-label">Найти партнера по телефону:</label>
                <div class="partner-input-group">
                    <input type="tel" 
                           id="partner-phone" 
                           class="partner-input" 
                           placeholder="Введите минимум 5 цифр"
                           oninput="formatPhoneInput(this)">
                    <button class="btn-search-partner" onclick="searchPartner(${tournament.id})">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div id="partner-result"></div>
            
            <div id="partner-selected" class="partner-selected hidden"></div>
            
            <button id="btn-register-team" class="btn-register-team hidden" onclick="registerTeam(${tournament.id})">
                Записаться на турнир
            </button>
        </div>
    `;
}

/**
 * Форматирование телефона при вводе
 */
function formatPhoneInput(input) {
    // Убираем всё кроме цифр
    input.value = input.value.replace(/[^\d]/g, '');
}

/**
 * Поиск партнера
 */
async function searchPartner(tournamentId) {
    const phoneInput = document.getElementById('partner-phone');
    const resultDiv = document.getElementById('partner-result');
    const phone = phoneInput.value.replace(/\D/g, '');
    
    if (phone.length < 5) {
        resultDiv.innerHTML = `<div class="partner-error">Введите минимум 5 цифр</div>`;
        return;
    }
    
    resultDiv.innerHTML = `<div class="partner-loading">Поиск...</div>`;
    
    try {
        const result = await apiSearchPartner(phone);
        
        if (result.error) {
            resultDiv.innerHTML = `<div class="partner-error">${result.error}</div>`;
            return;
        }
        
        if (result.found && result.partners && result.partners.length > 0) {
            resultDiv.innerHTML = `
                <div class="partners-list">
                    ${result.partners.map(partner => `
                        <div class="partner-found">
                            <div class="partner-info">
                                <div class="partner-avatar">${getInitial(partner.name)}</div>
                                <div class="partner-details">
                                    <div class="partner-name">${partner.name}</div>
                                    <div class="partner-meta">
                                        <span>Уровень: ${partner.level}</span>
                                        <span>Тел: ${partner.phone || '—'}</span>
                                    </div>
                                </div>
                            </div>
                            <button class="btn-select-partner" onclick="selectPartnerFromList(${partner.id}, '${partner.name}', ${partner.level})">
                                Выбрать
                            </button>
                        </div>
                    `).join('')}
                </div>
            `;
        } else {
            resultDiv.innerHTML = `<div class="partner-error">Игроки не найдены</div>`;
        }
    } catch (error) {
        resultDiv.innerHTML = `<div class="partner-error">Ошибка поиска</div>`;
    }
}

/**
 * Выбрать партнера из списка
 */
function selectPartnerFromList(id, name, level) {
    selectedPartner = { id, name, level };
    selectPartner();
}

/**
 * Выбрать партнера
 */
function selectPartner() {
    if (!selectedPartner) return;
    
    const resultDiv = document.getElementById('partner-result');
    const selectedDiv = document.getElementById('partner-selected');
    const registerBtn = document.getElementById('btn-register-team');
    
    resultDiv.innerHTML = '';
    
    selectedDiv.innerHTML = `
        <div class="partner-selected-card">
            <div class="partner-selected-title">Ваш партнер:</div>
            <div class="partner-info">
                <div class="partner-avatar">${getInitial(selectedPartner.name)}</div>
                <div class="partner-details">
                    <div class="partner-name">${selectedPartner.name}</div>
                    <div class="partner-level">Уровень: ${selectedPartner.level}</div>
                </div>
            </div>
            <button class="btn-change-partner" onclick="changePartner()">Изменить</button>
        </div>
    `;
    selectedDiv.classList.remove('hidden');
    registerBtn.classList.remove('hidden');
}

/**
 * Изменить партнера
 */
function changePartner() {
    selectedPartner = null;
    
    const selectedDiv = document.getElementById('partner-selected');
    const registerBtn = document.getElementById('btn-register-team');
    const phoneInput = document.getElementById('partner-phone');
    
    selectedDiv.innerHTML = '';
    selectedDiv.classList.add('hidden');
    registerBtn.classList.add('hidden');
    phoneInput.value = '';
    phoneInput.focus();
}

/**
 * Регистрация пары на турнир
 */
async function registerTeam(tournamentId) {
    if (!selectedPartner) {
        showToast('Выберите партнера');
        return;
    }
    
    const btn = document.getElementById('btn-register-team');
    btn.disabled = true;
    btn.innerHTML = 'Отправка...';
    
    try {
        const result = await apiRegisterTeam(tournamentId, selectedPartner.id);
        
        if (result.error) {
            showToast(result.error);
            btn.disabled = false;
            btn.innerHTML = 'Записаться на турнир';
            return;
        }
        
        if (result.success) {
            showToast('Заявка отправлена!');
            // Перезагружаем страницу турнира
            setTimeout(() => {
                openTournament(tournamentId);
            }, 1000);
        }
    } catch (error) {
        showToast('Ошибка регистрации');
        btn.disabled = false;
        btn.innerHTML = 'Записаться на турнир';
    }
}

/**
 * Отмена регистрации пары
 */
async function cancelTeamRegistration(tournamentId) {
    if (!confirm('Отменить регистрацию?')) return;
    
    try {
        const result = await apiCancelTeam(tournamentId);
        
        if (result.error) {
            showToast(result.error);
            return;
        }
        
        if (result.success) {
            showToast('Регистрация отменена');
            setTimeout(() => {
                openTournament(tournamentId);
            }, 1000);
        }
    } catch (error) {
        showToast('Ошибка отмены');
    }
}

/**
 * Рендер статуса регистрации для командного турнира
 */
function renderTeamRegistrationStatus(tournament, team) {
    if (team.status === 'pending') {
        return `
            <div class="team-status pending">
                <div class="alert alert-warning">
                    ⏳ Ваша заявка на модерации<br><br>
                    <strong>Ваша пара:</strong> ${team.player1} / ${team.player2}<br><br>
                    💳 Для подтверждения произведите оплату:<br>
                    <a href="https://pay.kaspi.kz/pay/g6b21oa4" target="_blank" class="payment-link">Оплатить через Kaspi</a><br><br>
                    📩 После оплаты сообщите администрации
                </div>
                <button class="btn-cancel" onclick="cancelTeamRegistration(${tournament.id})">
                    Отменить регистрацию
                </button>
            </div>
        `;
    } else if (team.status === 'approved') {
        return `
            <div class="team-status approved">
                <div class="alert alert-success">
                    ✅ Вы зарегистрированы<br><br>
                    <strong>Ваша пара:</strong> ${team.player1} / ${team.player2}
                </div>
                <button class="btn-cancel" onclick="cancelTeamRegistration(${tournament.id})">
                    Отменить регистрацию
                </button>
            </div>
        `;
    }
    
    return '';
}