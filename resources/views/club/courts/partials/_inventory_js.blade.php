{{-- Общий JS выбора инвентаря: добавление, количество, сумма.
     Подключается в дневном и недельном расписании. --}}
<script>
    // Выбранное по каждой модалке: { 'book': {itemId: {name, price, qty}}, 'edit': {...} }
    window.__invChosen = { book: {}, edit: {} };
    // Исторические строки — позицию либо удалили из справочника клуба
    // (club_inventory_item_id пуст), либо выключили (id есть, но её больше
    // нет среди активных). В обоих случаях выбрать её заново через пикер
    // нельзя, поэтому строки не участвуют в __invChosen и не уходят в форму
    // отдельными скрытыми полями: бэкенд сохраняет их сам при любом
    // сохранении брони. Здесь только для отображения и суммы «Итого».
    window.__invHistorical = { book: [], edit: [] };

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

    // Сумма за инвентарь в выбранной модалке — редактируемые строки +
    // исторические (позиция удалена из справочника, но деньги уже списаны).
    function inventoryTotal(mode) {
        let sum = 0;
        Object.values(window.__invChosen[mode]).forEach(r => { sum += r.price * r.qty; });
        (window.__invHistorical[mode] || []).forEach(r => { sum += r.price * r.quantity; });
        return sum;
    }

    // Перерисовать список выбранного и скрытые поля формы.
    function renderInventoryPicker(mode) {
        const box = document.getElementById(mode + 'InventoryChosen');
        if (!box) return;
        const store = window.__invChosen[mode];
        box.innerHTML = '';

        // Исторические строки — показываем первыми, без кнопок количества и
        // удаления: менять их через интерфейс нельзя, они не заводят позицию
        // повторно, а только объясняют админу, за что уже списаны деньги.
        (window.__invHistorical[mode] || []).forEach(row => {
            const el = document.createElement('div');
            el.className = 'inv-row inv-row-historical';

            const nameEl = document.createElement('span');
            nameEl.className = 'inv-row-name';
            nameEl.textContent = row.name + ' × ' + row.quantity; // textContent — название пришло от пользователя
            el.appendChild(nameEl);

            const note = document.createElement('span');
            note.className = 'inv-row-note';
            note.textContent = row.__reason === 'inactive' ? 'позиция выключена в справочнике' : 'позиция удалена из справочника';
            el.appendChild(note);

            const sum = document.createElement('span');
            sum.className = 'inv-row-sum';
            sum.textContent = invFmt(row.price * row.quantity) + ' ₸';
            el.appendChild(sum);

            box.appendChild(el);
        });

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
    // rows — инвентарь из данных самой брони (payload data.inventory),
    // он не зависит от видимой даты/недели и есть у любой открытой брони.
    // window.__bookingInventory (карта по видимому дню/неделе) — запасной
    // вариант для мест, где payload ещё не содержит инвентарь.
    function applyBookingInventory(bookingId, rows) {
        window.__invChosen.edit = {};
        window.__invHistorical.edit = [];
        const list = rows || (window.__bookingInventory && window.__bookingInventory[bookingId]) || [];
        // Активные позиции справочника — по ним же пикер рисует кнопки
        // выбора. Строка брони, чей item_id среди них не числится (позицию
        // выключили, а не только если её удалили совсем), редактировать
        // нечем — переносим её в исторические, тот же путь, что и для
        // удалённых.
        const activeIds = new Set((window.__inventory || []).map(it => it.id));
        list.forEach(r => {
            if (!r.item_id) {
                r.__reason = 'deleted';
                window.__invHistorical.edit.push(r);
                return;
            }
            if (!activeIds.has(r.item_id)) {
                r.__reason = 'inactive';
                window.__invHistorical.edit.push(r);
                return;
            }
            window.__invChosen.edit[r.item_id] = { name: r.name, price: r.price, qty: r.quantity };
        });
        renderInventoryPicker('edit');
    }

    // Сбросить выбор в модалке создания.
    function resetBookInventory() {
        window.__invChosen.book = {};
        window.__invHistorical.book = [];
        renderInventoryPicker('book');
    }

    // В окне редактирования аналога resetBookInventory() нет НАМЕРЕННО:
    // при переходе в group/tournament состояние __invChosen.edit не трогаем.
    // Блок сам прячется классом js-edit-hide-for-group (см. selectEditBookingType
    // во вьюхе), а его скрытые поля в запросе безвредны — контроллер для этих
    // типов вызывает sync() с пустым набором сам и присланный inventory не
    // читает. Если бы состояние сбрасывалось, путь «обычная → групповая →
    // обратно обычная» в одном открытом окне стирал бы валидный набор
    // навсегда; без сброса возврат к обычному типу просто снова показывает
    // блок с прежним выбором.
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
.inv-row-historical{opacity:0.6;border-style:dashed}
.inv-row-note{font-size:11px;color:var(--text-muted);font-style:italic}
</style>
