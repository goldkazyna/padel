/* ============================================
   RATING.JS - Логика страницы рейтинга
   ============================================ */

let ratingData = [];
let currentPage = 1;
let totalPages = 1;
let myPage = 1;
let myRank = null;
let currentLevel = 'all';

/**
 * Загрузить рейтинг
 */
async function loadRating(page = 1) {
    const result = await apiRating(page, currentLevel);
    
    ratingData = result.players || [];
    currentPage = result.page || 1;
    totalPages = result.total_pages || 1;
    myPage = result.my_page || 1;
    myRank = result.my_rank;
    
    renderMyRank(result);
    renderRankingList(ratingData);
    renderPagination();
    setupLevelTabs();
}

/**
 * Настройка табов по уровням
 */
function setupLevelTabs() {
    document.querySelectorAll('#rating-tabs .tg-tab').forEach(tab => {
        tab.addEventListener('click', async () => {
            document.querySelectorAll('#rating-tabs .tg-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            
            currentLevel = tab.dataset.level;
            currentPage = 1;
            await loadRating(1);
        });
    });
}

/**
 * Рендер моей позиции
 */
function renderMyRank(data) {
    const container = document.getElementById('my-rank-card');
    if (!container || !currentUser) return;
    
    const rank = data.my_rank || '-';
    
    container.innerHTML = `
        <div class="tg-user-info">
            <span class="tg-user-rank">#${rank}</span>
            <div class="tg-user-details">
                <span class="tg-user-label">Вы</span>
                <span class="tg-user-pts">${currentUser.rating || 1000} pts</span>
            </div>
        </div>
        <button class="tg-find-me-btn" onclick="goToMyPosition()" title="Найти себя">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <circle cx="12" cy="12" r="3"/>
                <path d="M12 2v4"/>
                <path d="M12 18v4"/>
                <path d="M2 12h4"/>
                <path d="M18 12h4"/>
            </svg>
        </button>
    `;
}

/**
 * Рендер списка рейтинга
 */
function renderRankingList(players) {
    const container = document.getElementById('ranking-list');
    if (!container) return;
    
    if (players.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">📊</div>
                <div class="empty-state-title">Нет данных</div>
                <div class="empty-state-text">Рейтинг пока пуст</div>
            </div>
        `;
        return;
    }
    
    container.innerHTML = players.map(player => renderRankingItem(player)).join('');
}

/**
 * Рендер элемента рейтинга
 */
function renderRankingItem(player) {
    const initial = getInitial(player.name);
    const position = player.position;
    
    let positionClass = '';
    let positionColorClass = '';
    
    if (position === 1) {
        positionClass = 'top-1';
        positionColorClass = 'gold';
    } else if (position === 2) {
        positionClass = 'top-2';
        positionColorClass = 'silver';
    } else if (position === 3) {
        positionClass = 'top-3';
        positionColorClass = 'bronze';
    }
    
    const isMe = currentUser && player.id === currentUser.id;
    
    return `
        <div class="tg-rating-item ${positionClass} ${isMe ? 'is-me' : ''}" ${isMe ? 'id="my-ranking-item"' : ''}>
            <span class="tg-position ${positionColorClass}">${position}</span>
            <div class="tg-avatar">${initial}</div>
            <div class="tg-player-info">
                <div class="tg-player-name">${player.name}${isMe ? ' (Вы)' : ''}</div>
                <div class="tg-player-level">Уровень ${player.level}</div>
            </div>
            <span class="tg-player-rating">${player.rating}</span>
        </div>
    `;
}

/**
 * Рендер пагинации
 */
function renderPagination() {
    const container = document.getElementById('rating-pagination');
    if (!container) return;
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let pages = [];
    
    if (totalPages <= 5) {
        for (let i = 1; i <= totalPages; i++) {
            pages.push(i);
        }
    } else {
        if (currentPage <= 3) {
            pages = [1, 2, 3, 4, '...', totalPages];
        } else if (currentPage >= totalPages - 2) {
            pages = [1, '...', totalPages - 3, totalPages - 2, totalPages - 1, totalPages];
        } else {
            pages = [1, '...', currentPage - 1, currentPage, currentPage + 1, '...', totalPages];
        }
    }
    
    container.innerHTML = `
        <button class="tg-page-btn" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </button>
        
        ${pages.map(p => {
            if (p === '...') {
                return `<span class="tg-page-dots">...</span>`;
            }
            return `<button class="tg-page-btn ${p === currentPage ? 'active' : ''}" onclick="changePage(${p})">${p}</button>`;
        }).join('')}
        
        <button class="tg-page-btn" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"/>
            </svg>
        </button>
    `;
}

/**
 * Сменить страницу
 */
async function changePage(page) {
    if (page < 1 || page > totalPages || page === currentPage) return;
    await loadRating(page);
    
    document.getElementById('ranking-list')?.scrollIntoView({ behavior: 'smooth' });
}

/**
 * Перейти к своей позиции
 */
async function goToMyPosition() {
    if (!myPage) return;
    
    // Сбрасываем фильтр на "Общий"
    currentLevel = 'all';
    document.querySelectorAll('#rating-tabs .tg-tab').forEach(t => t.classList.remove('active'));
    document.querySelector('#rating-tabs .tg-tab[data-level="all"]')?.classList.add('active');
    
    await loadRating(myPage);
    
    setTimeout(() => {
        const myItem = document.getElementById('my-ranking-item');
        if (myItem) {
            myItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
            myItem.classList.add('highlight');
            setTimeout(() => myItem.classList.remove('highlight'), 2000);
        }
    }, 100);
}