{{-- Редизайн секций брони (тип / способ оплаты / статус) — плитки с иконками.
     Подключается в day и week расписании. Переопределяет .bt-btn/.pay-btn/.paid-btn. --}}
<style>
    /* контейнеры — сетка плиток */
    .booking-type-buttons { display: grid !important; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 4px; }
    .payment-methods { display: grid !important; grid-template-columns: repeat(4, 1fr); gap: 8px; }

    /* плитка типа брони — иконка слева + текст */
    .bt-btn {
        position: relative; display: flex; align-items: center; gap: 10px;
        flex: none !important; min-width: 0 !important; width: auto !important;
        padding: 12px 12px !important; text-align: left !important;
        background: var(--sch-card-alt); border: 1px solid var(--sch-border); border-radius: 12px;
        color: var(--sch-text); font-size: 13px; font-weight: 600; transition: all .15s;
    }
    /* плитка способа оплаты — иконка сверху + текст снизу */
    .pay-btn {
        position: relative; display: flex; flex-direction: column; align-items: flex-start; gap: 8px;
        flex: none !important; min-width: 0 !important; width: auto !important;
        padding: 12px 12px !important; text-align: left !important;
        background: var(--sch-card-alt); border: 1px solid var(--sch-border); border-radius: 12px;
        color: var(--sch-text); font-size: 13px; font-weight: 600; transition: all .15s;
    }
    .bt-btn > i, .pay-btn > i { font-size: 18px; line-height: 1; }
    .bt-btn > span, .pay-btn > span { display: block; }

    .bt-btn:hover:not(.active), .pay-btn:hover:not(.active) { border-color: var(--sch-text-dim); }

    /* выбранная плитка — зелёная + галочка в углу */
    .bt-btn.active, .pay-btn.active {
        background: rgba(34,197,94,.12) !important;
        border-color: #22c55e !important;
        color: #fff !important;
    }
    .bt-btn.active::after, .pay-btn.active::after {
        content: "✓"; position: absolute; top: 8px; right: 10px;
        width: 18px; height: 18px; border-radius: 50%;
        background: #22c55e; color: #06210f;
        display: flex; align-items: center; justify-content: center;
        font-size: 11px; font-weight: 800;
    }

    /* цвета иконок */
    .bt-btn[data-value="soft"] > i { color: #f59e0b; }
    .bt-btn[data-value="group"] > i { color: #3b82f6; }
    .bt-btn[data-value="individual"] > i { color: #22d3ee; }
    .bt-btn[data-value="tournament"] > i { color: #a78bfa; }
    .pay-btn[data-value="cash"] > i { color: #22c55e; }
    .pay-btn[data-value="card"] > i { color: #60a5fa; }
    .pay-btn[data-value="kaspi"] > i { color: #ef4444; }
    .pay-btn[data-value="deposit"] > i { color: #3b82f6; }
    .pay-btn[data-value="club_card"] > i { color: #a78bfa; }
    .pay-btn[data-value="certificate"] > i { color: #fbbf24; }
    .pay-btn[data-value="cashback"] > i { color: #22d3ee; }
    .bt-btn.active > i, .pay-btn.active > i { color: #22c55e !important; }

    /* статус оплаты — две крупные плитки с точкой */
    .paid-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .paid-btn {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        padding: 14px !important; border-radius: 12px; font-size: 13px; font-weight: 700;
        border: 1px solid var(--sch-border); background: var(--sch-card-alt); color: var(--sch-text-dim);
    }
    .paid-btn::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; opacity: .7; }

    @media (max-width: 560px) {
        .payment-methods { grid-template-columns: repeat(2, 1fr); }
        .booking-type-buttons { grid-template-columns: repeat(2, 1fr); }
    }
</style>
