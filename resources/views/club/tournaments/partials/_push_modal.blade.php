{{-- Окно отправки push для турнира или этапа лиги.
     Кнопка-колокольчик зовёт openPushModal(this) и передаёт в data-атрибутах
     адрес отправки, название и заготовку текста. Подключается и в списке
     турниров, и на странице лиги — рассылка у них одна и та же. --}}
{{-- Окно отправки push: текст заполняется заготовкой, организатор правит --}}
<div class="push-overlay" id="pushOverlay" onclick="if(event.target === this) closePushModal()">
    <form method="POST" id="pushForm" class="push-modal">
        @csrf
        <div class="push-head">
            <div>
                <div class="push-eyebrow"><i class="bi bi-bell"></i> Push-уведомление</div>
                <div class="push-tournament" id="pushTournament"></div>
            </div>
            <button type="button" class="push-close" onclick="closePushModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        @php $pushTestPhones = app(\App\Services\TournamentPushService::class)->testPhones(); @endphp
        @if($pushTestPhones)
            <div class="push-testmode">
                <i class="bi bi-cone-striped"></i>
                <div>
                    <b>Тестовый режим</b>
                    Уведомление уйдёт только на {{ implode(', ', $pushTestPhones) }} —
                    остальные игроки ничего не получат. Снимается строкой
                    <code>PUSH_TEST_PHONES</code> в <code>.env</code>.
                </div>
            </div>
        @endif

        <label class="push-label">Заголовок</label>
        <input type="text" name="push_title" id="pushTitle" class="push-input"
               maxlength="100" required>
        <div class="push-counter"><span id="pushTitleLeft"></span></div>

        <label class="push-label">Текст</label>
        <textarea name="push_body" id="pushBody" class="push-input push-area"
                  maxlength="250" required></textarea>
        <div class="push-counter"><span id="pushBodyLeft"></span></div>

        {{-- На телефоне пуш обрезается: длинный текст увидят не полностью --}}
        <div class="push-preview">
            <div class="push-preview-label">Как увидит игрок</div>
            <div class="push-phone">
                <div class="push-phone-app">Padel KZ · сейчас</div>
                <div class="push-phone-title" id="pushPreviewTitle"></div>
                <div class="push-phone-body" id="pushPreviewBody"></div>
            </div>
        </div>

        <div class="push-actions">
            <button type="button" class="push-btn-ghost" onclick="resetPushText()">
                Вернуть заготовку
            </button>
            <button type="submit" class="push-btn" id="pushSubmit">
                <span class="push-spinner"></span>
                <span class="push-btn-text"><i class="bi bi-send"></i> Отправить</span>
            </button>
        </div>
    </form>
</div>


<script>
let pushDefaults = { title: '', body: '' };

function openPushModal(btn) {
    pushDefaults = { title: btn.dataset.title, body: btn.dataset.body };

    document.getElementById('pushForm').action = btn.dataset.action;
    document.getElementById('pushTournament').textContent = btn.dataset.tournament;
    document.getElementById('pushTitle').value = btn.dataset.title;
    document.getElementById('pushBody').value = btn.dataset.body;

    refreshPushPreview();
    document.getElementById('pushOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closePushModal() {
    document.getElementById('pushOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

function resetPushText() {
    document.getElementById('pushTitle').value = pushDefaults.title;
    document.getElementById('pushBody').value = pushDefaults.body;
    refreshPushPreview();
}

function refreshPushPreview() {
    const title = document.getElementById('pushTitle');
    const body = document.getElementById('pushBody');

    document.getElementById('pushPreviewTitle').textContent = title.value || '—';
    document.getElementById('pushPreviewBody').textContent = body.value || '—';
    document.getElementById('pushTitleLeft').textContent =
        title.value.length + ' / ' + title.maxLength;
    document.getElementById('pushBodyLeft').textContent =
        body.value.length + ' / ' + body.maxLength;
}

document.addEventListener('DOMContentLoaded', function () {
    ['pushTitle', 'pushBody'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', refreshPushPreview);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePushModal();
    });

    // Рассылка идёт несколько секунд. Без блокировки нетерпеливый клик
    // отправляет push повторно — игроки получают его дважды.
    document.getElementById('pushForm').addEventListener('submit', function (e) {
        var btn = document.getElementById('pushSubmit');

        if (btn.disabled) {
            e.preventDefault();
            return;
        }

        // Форма уже собрала данные — кнопку можно гасить.
        btn.disabled = true;
        btn.classList.add('sending');
        btn.querySelector('.push-btn-text').textContent = 'Отправляем…';
        document.querySelector('.push-btn-ghost').disabled = true;
        document.querySelector('.push-close').disabled = true;
    });
});
</script>

<style>
/* ---- окно отправки push ---- */
.push-overlay {
    display: none;
    position: fixed; inset: 0; z-index: 1000;
    background: rgba(0, 0, 0, .6);
    backdrop-filter: blur(2px);
    padding: 20px;
    overflow-y: auto;
}
.push-overlay.open { display: flex; align-items: center; justify-content: center; }
.push-modal {
    width: 100%; max-width: 480px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 22px 24px;
}
.push-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
.push-eyebrow {
    display: flex; align-items: center; gap: 7px;
    color: var(--accent);
    font-size: .74rem; font-weight: 700;
    letter-spacing: .1em; text-transform: uppercase;
    margin-bottom: 5px;
}
.push-tournament { color: var(--text-primary); font-size: 1.08rem; font-weight: 600; }
.push-close {
    margin-left: auto; flex-shrink: 0;
    background: transparent; border: none;
    color: var(--text-secondary); cursor: pointer;
    font-size: 1rem; padding: 4px;
}
.push-close:hover { color: var(--text-primary); }
.push-testmode {
    display: flex; gap: 11px; align-items: flex-start;
    background: rgba(245, 158, 11, .12);
    border: 1px solid #f59e0b;
    border-radius: 11px;
    padding: 12px 14px;
    margin-bottom: 18px;
    color: #f59e0b;
    font-size: .85rem; line-height: 1.45;
}
.push-testmode i { font-size: 1.05rem; flex-shrink: 0; margin-top: 1px; }
.push-testmode b { display: block; margin-bottom: 2px; }
.push-testmode code {
    background: rgba(0, 0, 0, .25);
    border-radius: 4px; padding: 1px 5px;
    font-size: .82rem;
}
.push-label {
    display: block;
    color: var(--text-secondary);
    font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;
    margin-bottom: 6px;
}
.push-input {
    width: 100%;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 11px 14px;
    color: var(--text-primary);
    font-size: .95rem;
    font-family: inherit;
}
.push-input:focus { outline: none; border-color: var(--accent); }
.push-area { min-height: 76px; resize: vertical; }
.push-counter {
    text-align: right;
    color: var(--text-secondary);
    font-size: .74rem;
    margin: 4px 0 14px;
}
.push-preview { margin-bottom: 18px; }
.push-preview-label {
    color: var(--text-secondary);
    font-size: .78rem; text-transform: uppercase; letter-spacing: .06em;
    margin-bottom: 8px;
}
.push-phone {
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
}
.push-phone-app { color: var(--text-secondary); font-size: .72rem; margin-bottom: 5px; }
.push-phone-title {
    color: var(--text-primary); font-weight: 600; font-size: .92rem;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.push-phone-body {
    color: var(--text-secondary); font-size: .88rem; line-height: 1.35;
    margin-top: 2px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    overflow: hidden;
}
.push-actions { display: flex; gap: 10px; }
.push-btn {
    flex: 1;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    background: var(--accent); color: #000;
    border: none; border-radius: 10px;
    padding: 12px 20px;
    font-size: .95rem; font-weight: 600;
    cursor: pointer;
}
.push-btn-ghost {
    background: transparent; color: var(--text-secondary);
    border: 1px solid var(--border); border-radius: 10px;
    padding: 12px 16px;
    font-size: .9rem; cursor: pointer;
}
.push-btn-ghost:hover { color: var(--text-primary); border-color: var(--border-light); }
.push-btn:disabled, .push-btn-ghost:disabled { opacity: .55; cursor: not-allowed; }
.push-close:disabled { opacity: .35; cursor: not-allowed; }

/* Спиннер появляется только на время отправки */
.push-spinner { display: none; }
.push-btn.sending .push-spinner {
    display: inline-block;
    width: 15px; height: 15px;
    border: 2px solid rgba(0, 0, 0, .25);
    border-top-color: #000;
    border-radius: 50%;
    animation: push-spin .7s linear infinite;
}
@keyframes push-spin { to { transform: rotate(360deg); } }

.btn-push {
    color: #f59e0b;
    border-color: #f59e0b;
}

.btn-push:hover {
    background: #f59e0b;
    color: white;
}

/* Счётчик оставшихся рассылок рядом с колокольчиком */
.push-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
    margin-right: 6px;
}
.push-left {
    margin-left: 3px;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    border-radius: 8px;
    background: rgba(245, 158, 11, .16);
    color: #f59e0b;
    font-size: 10px;
    font-weight: 800;
    line-height: 16px;
    text-align: center;
}
.push-left.is-spent {
    background: rgba(148, 163, 184, .16);
    color: #94a3b8;
}
.btn-push.is-spent {
    color: #94a3b8;
    border-color: #3f3f46;
    cursor: not-allowed;
}
.btn-push.is-spent:hover {
    background: transparent;
    color: #94a3b8;
}
</style>
