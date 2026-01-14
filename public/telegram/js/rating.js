/* ============================================
   RATING.JS - Логика страницы рейтинга
   ============================================ */

let ratingData = [];
let currentPage = 1;
let totalPages = 1;
let myPage = 1;
let myRank = null;

/**
 * Загрузить рейтинг
 */
async function loadRating(page = 1) {
    const result = await apiRating(page);
    
    ratingData = result.players || [];
    currentPage = result.page || 1;
    totalPages = result.total_pages || 1;
    myPage = result.my_page || 1;
    myRank = result.my_rank;
    
    renderMyRank(result);
    renderRankingList(ratingData);
    renderPagination();
}

/**
 * Рендер моей позиции
 */
function renderMyRank(data) {
    const container = document.getElementById('my-rank-card');
    if (!container || !currentUser) return;
    
    const rank = data.my_rank || '-';
    const change = data.my_change || 0;
    const changeText = change >= 0 ? `+${change}` : change;
    const changeClass = change >= 0 ? 'positive' : 'negative';
    
    container.innerHTML = `
        <div class="my-rank-left">
            <div class="my-rank-position">#${rank}</div>
            <div class="my-rank-info">
                <div class="my-rank-name">Вы</div>
                <div class="my-rank-rating">${currentUser.rating || 1000} pts</div>
            </div>
        </div>
        <button class="find-me-btn" onclick="goToMyPosition()" title="Найти себя">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
    
    container.innerHTML = `
        <div class="ranking-list">
            ${players.map(player => renderRankingItem(player)).join('')}
        </div>
    `;
}

/**
 * Рендер элемента рейтинга
 */
function renderRankingItem(player) {
    const initial = getInitial(player.name);
    const position = player.position;
    
    let positionClass = '';
    if (position === 1) positionClass = 'top-1';
    else if (position === 2) positionClass = 'top-2';
    else if (position === 3) positionClass = 'top-3';
    
    const isMe = currentUser && player.id === currentUser.id;
    
    return `
        <div class="ranking-item ${positionClass} ${isMe ? 'is-me' : ''}" ${isMe ? 'id="my-ranking-item"' : ''}>
            <div class="ranking-position">${position}</div>
            <div class="ranking-avatar">${initial}</div>
            <div class="ranking-info">
                <div class="ranking-name">${player.name}${isMe ? ' (Вы)' : ''}</div>
                <div class="ranking-level">Уровень ${player.level}</div>
            </div>
            <div class="ranking-rating">${player.rating}</div>
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
    
    // Логика отображения страниц
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
        <div class="pagination">
            <button class="pagination-btn" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15 18l-6-6 6-6"/>
                </svg>
            </button>
            
            ${pages.map(p => {
                if (p === '...') {
                    return `<span class="pagination-dots">...</span>`;
                }
                return `<button class="pagination-num ${p === currentPage ? 'active' : ''}" onclick="changePage(${p})">${p}</button>`;
            }).join('')}
            
            <button class="pagination-btn" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 18l6-6-6-6"/>
                </svg>
            </button>
        </div>
    `;
}

/**
 * Сменить страницу
 */
async function changePage(page) {
    if (page < 1 || page > totalPages || page === currentPage) return;
    await loadRating(page);
    
    // Скролл наверх списка
    document.getElementById('ranking-list')?.scrollIntoView({ behavior: 'smooth' });
}

/**
 * Перейти к своей позиции
 */
async function goToMyPosition() {
    if (!myPage) return;
    
    await loadRating(myPage);
    
    // Подождём рендер и скролл к себе
    setTimeout(() => {
        const myItem = document.getElementById('my-ranking-item');
        if (myItem) {
            myItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Подсветка
            myItem.classList.add('highlight');
            setTimeout(() => myItem.classList.remove('highlight'), 2000);
        }
    }, 100);
}