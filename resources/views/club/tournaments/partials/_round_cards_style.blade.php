{{-- resources/views/club/tournaments/partials/_round_cards_style.blade.php --}}
{{-- Общий вид карточек раундов и матчей: «Король корта» и «Эскалера» подключают
     этот файл, чтобы ввод счёта в обоих форматах выглядел одинаково.
     Базовые классы (.round-card, .match-card, .btn-score, .team-players и т.д.)
     живут в public/css/tournament-show.css — здесь только переопределения. --}}
<style>
.round-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: var(--bg-secondary); user-select: none; transition: all 0.3s; }
.round-header:hover { background: rgba(255, 255, 255, 0.08); }
.round-header-right { display: flex; align-items: center; gap: 12px; }
.collapse-icon { transition: transform 0.3s; color: var(--text-secondary); }
.collapse-icon.collapsed { transform: rotate(-90deg); }
.collapsible-content { max-height: 5000px; overflow: hidden; transition: max-height 0.3s ease-out, opacity 0.3s, padding 0.3s; opacity: 1; padding: 12px; }
.collapsible-content.collapsed { max-height: 0; opacity: 0; padding: 0 12px; }

.round-card.active { border: 2px solid var(--accent); box-shadow: 0 0 20px rgba(34, 197, 94, 0.3); background: var(--bg-card); }
.round-card.active .round-header { background: rgba(34, 197, 94, 0.15); }
.round-card.active .round-title { color: var(--accent); font-size: 1.3rem; }
.round-card.completed { opacity: 0.6; }
.round-card.pending { opacity: 0.4; }
.round-card.completed:hover, .round-card.pending:hover { opacity: 1; }

.round-status.completed { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
.round-status.active { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
.round-status.pending { background: rgba(156, 163, 175, 0.15); color: #9ca3af; }

.match-card { display: flex; flex-direction: column; align-items: center; }
.match-teams { display: flex; align-items: center; width: 100%; justify-content: space-between; }

.match-court-header {
    text-align: center;
    font-size: 22px;
    padding: 6px 16px;
    border-radius: 20px;
    font-weight: 600;
    margin-bottom: 12px;
    display: inline-block;
    width: 100%;
}
.match-court-header.court-top { color: #fbbf24; background: rgba(251, 191, 36, 0.12); }
.match-court-header.court-middle { color: #0dcaf0; background: rgba(13, 202, 240, 0.10); }
.match-court-header.court-bottom { color: #f87171; background: rgba(248, 113, 113, 0.10); }

.rounds-grid { display: grid; grid-template-columns: repeat(1, 1fr); gap: 16px; }
.player-line { font-size: 30px; }
.team-score { font-size: 40px; }
.score-team-names { font-size: 22px; }
</style>
