{{-- Общий JS выбора инвентаря: добавление, количество, сумма.
     Подключается в дневном и недельном расписании. --}}
<script>
    // Выбранное по каждой модалке: { 'book': {itemId: {name, price, qty}}, 'edit': {...} }
    window.__invChosen = { book: {}, edit: {} };

    function invFmt(n) { return new Intl.NumberFormat('ru-RU').format(n); }

    // Добавить позицию или увеличить её количество.
    function addInventory(mode, itemId, name, price) {
        const store = window.__invChosen[mode];
        if (store[itemId]) {
            store[itemId].qty += 1;
        } else {
            store[itemId] = { name: name, price: price, qty: 1 };
        }
        renderInventoryPicker(mode);
    }

    function changeInventoryQty(mode, itemId, delta) {
        const store = window.__invChosen[mode];
        if (!store[itemId]) return;
        store[itemId].qty += delta;
        if (store[itemId].qty <= 0) delete store[itemId];
        renderInventoryPicker(mode);
    }

    function removeInventory(mode, itemId) {
        delete window.__invChosen[mode][itemId];
        renderInventoryPicker(mode);
    }

    // Сумма за инвентарь в выбранной модалке.
    function inventoryTotal(mode) {
        let sum = 0;
        Object.values(window.__invChosen[mode]).forEach(r => { sum += r.price * r.qty; });
        return sum;
    }

    // Перерисовать список выбранного и скрытые поля формы.
    function renderInventoryPicker(mode) {
        const box = document.getElementById(mode + 'InventoryChosen');
        if (!box) return;
        const store = window.__invChosen[mode];
        box.innerHTML = '';

        Object.keys(store).forEach((itemId, i) => {
            const row = store[itemId];
            const el = document.createElement('div');
            el.className = 'inv-row';

            const nameEl = document.createElement('span');
            nameEl.className = 'inv-row-name';
            nameEl.textContent = row.name; // textContent — название пришло от пользователя
            el.appendChild(nameEl);

            const qty = document.createElement('span');
            qty.className = 'inv-qty';
            qty.innerHTML =
                '<button type="button" class="inv-qty-btn" onclick="changeInventoryQty(\'' + mode + '\',' + itemId + ',-1)">−</button>' +
                '<span class="inv-qty-num">' + row.qty + '</span>' +
                '<button type="button" class="inv-qty-btn" onclick="changeInventoryQty(\'' + mode + '\',' + itemId + ',1)">+</button>';
            el.appendChild(qty);

            const sum = document.createElement('span');
            sum.className = 'inv-row-sum';
            sum.textContent = invFmt(row.price * row.qty) + ' ₸';
            el.appendChild(sum);

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'inv-row-del';
            del.innerHTML = '&#10005;';
            del.onclick = () => removeInventory(mode, itemId);
            el.appendChild(del);

            // Скрытые поля формы
            const fId = document.createElement('input');
            fId.type = 'hidden';
            fId.name = 'inventory[' + i + '][item_id]';
            fId.value = itemId;
            el.appendChild(fId);

            const fQty = document.createElement('input');
            fQty.type = 'hidden';
            fQty.name = 'inventory[' + i + '][quantity]';
            fQty.value = row.qty;
            el.appendChild(fQty);

            box.appendChild(el);
        });

        // Пересчёт «Итого» — функции определены во вьюхе.
        if (mode === 'book' && typeof updateFinalPrice === 'function') updateFinalPrice();
        if (mode === 'edit' && typeof updateEditFinalPrice === 'function') updateEditFinalPrice();
    }

    // Подставить инвентарь открытой брони в модалку редактирования.
    function applyBookingInventory(bookingId) {
        window.__invChosen.edit = {};
        const rows = (window.__bookingInventory && window.__bookingInventory[bookingId]) || [];
        rows.forEach(r => {
            if (!r.item_id) return; // позиция удалена из справочника — не подставляем
            window.__invChosen.edit[r.item_id] = { name: r.name, price: r.price, qty: r.quantity };
        });
        renderInventoryPicker('edit');
    }

    // Сбросить выбор в модалке создания.
    function resetBookInventory() {
        window.__invChosen.book = {};
        renderInventoryPicker('book');
    }
</script>

<style>
.inv-pick{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.inv-pick-btn{display:flex;flex-direction:column;align-items:flex-start;gap:2px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px 12px;cursor:pointer;color:var(--text-primary)}
.inv-pick-btn:hover{background:var(--bg-card-hover);border-color:var(--accent)}
.inv-pick-name{font-size:13px;font-weight:700}
.inv-pick-price{font-size:12px;color:var(--accent);font-weight:700}
.inv-chosen{display:flex;flex-direction:column;gap:6px}
.inv-row{display:flex;align-items:center;gap:10px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:8px 12px}
.inv-row-name{flex:1;font-size:13px}
.inv-qty{display:flex;align-items:center;gap:8px}
.inv-qty-btn{background:transparent;border:1px solid var(--border);color:var(--text-secondary);border-radius:6px;width:24px;height:24px;cursor:pointer;line-height:1}
.inv-qty-btn:hover{color:var(--text-primary)}
.inv-qty-num{min-width:18px;text-align:center;font-weight:700;font-size:13px}
.inv-row-sum{min-width:90px;text-align:right;font-weight:700;font-size:13px;color:var(--accent)}
.inv-row-del{background:transparent;border:none;color:var(--text-muted);cursor:pointer;font-size:13px}
.inv-row-del:hover{color:#ef4444}
</style>
