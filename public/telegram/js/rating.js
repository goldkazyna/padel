/* ============================================
   RATING.JS - Логика страницы рейтинга
   ============================================ */

let ratingData = [];

/**
 * Загрузить рейтинг
 */
async function loadRating() {
    const result = await apiRating();
    ratingData = result.players || [];
    
    renderMyRank(result);
    renderRankingList(ratingData);
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
    
    container.innerHTML = `
        <div class="my-rank-position">#${rank}</div>
        <div class="my-rank-info">
            <div class="my-rank-name">Вы</div>
            <div class="my-rank-rating">${currentUser.rating || 1000} pts</div>
        </div>
        <div class="my-rank-change ${change >= 0 ? 'positive' : 'negative'}">${changeText}</div>
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
            ${players.map((player, index) => renderRankingItem(player, index + 1)).join('')}
        </div>
    `;
}

/**
 * Рендер элемента рейтинга
 */
function renderRankingItem(player, position) {
    const initial = getInitial(player.name);
    
    let positionClass = '';
    if (position === 1) positionClass = 'top-1';
    else if (position === 2) positionClass = 'top-2';
    else if (position === 3) positionClass = 'top-3';
    
    const isMe = currentUser && player.id === currentUser.id;
    
    return `
        <div class="ranking-item ${positionClass} ${isMe ? 'is-me' : ''}">
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
