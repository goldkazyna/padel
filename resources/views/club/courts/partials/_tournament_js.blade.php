{{-- Общий JS турнирной брони: расчёт цены и список участников.
     Подключается в дневном и недельном расписании. --}}
<script>
    // Единый рендер панели турнира — и для создания, и для редактирования.
    // Оба окна показывают одно и то же: расчёт цены, список оплативших, ссылку
    // на турнир. Различаются только id элементов и то, учтена ли уже эта бронь
    // в делителе (при создании брони ещё нет, при редактировании она есть).
    function renderTournamentPanel(ids, tournamentId, date, countsSelf) {
        const block = document.getElementById(ids.block);
        const list = document.getElementById(ids.list);
        const empty = document.getElementById(ids.empty);
        const count = document.getElementById(ids.count);
        const priceEl = document.getElementById(ids.price);
        const link = document.getElementById(ids.link);
        if (!block || !list) return;

        if (!tournamentId) {
            block.style.display = 'none';
            list.innerHTML = '';
            if (priceEl) priceEl.style.display = 'none';
            if (link) link.style.display = 'none';
            return;
        }

        const data = (window.__tournaments && window.__tournaments[tournamentId]) || null;
        if (!data) { block.style.display = 'none'; return; }

        const fmt = n => new Intl.NumberFormat('ru-RU').format(n);
        const existing = (data.bookings_by_date && data.bookings_by_date[date]) || 0;
        // countsSelf = true, когда бронь уже среди существующих (редактирование).
        const divisor = Math.max(1, countsSelf ? existing : existing + 1);
        const share = Math.round(data.total / divisor);

        if (priceEl) {
            if (!data.price) {
                priceEl.innerHTML = 'У турнира не указана цена — <a href="{{ url('club/tournaments') }}/' + data.id + '/edit" target="_blank" style="color:#a78bfa;">укажите цену в турнире</a>';
            } else {
                let html = data.paid_count + ' оплативших × ' + fmt(data.price) + ' = <b>' + fmt(data.total) + ' ₸</b>';
                if (divisor > 1) {
                    html += '<br>делится на ' + divisor + ' корта → <b>' + fmt(share) + ' ₸</b> за этот корт';
                }
                priceEl.innerHTML = html;
            }
            priceEl.style.display = 'block';
        }

        if (link) {
            link.href = '{{ url('club/tournaments') }}/' + data.id;
            link.style.display = 'flex';
        }

        const players = data.participants || [];
        if (count) count.textContent = players.length ? players.length : '';
        list.innerHTML = '';
        players.forEach(n => {
            const li = document.createElement('li');
            li.className = 'gm-item';
            li.textContent = n; // textContent, а не innerHTML — имя пришло от пользователя
            list.appendChild(li);
        });
        if (empty) empty.style.display = players.length ? 'none' : 'block';
        block.style.display = 'block';
    }

    // Информация о выбранном турнире в окне СОЗДАНИЯ.
    function renderTournamentInfo(tournamentId) {
        // Дату даёт вьюха: в дневном виде она одна на страницу, в недельном —
        // из выбранного слота. Функция tournamentBookingDate() определена во вьюхе.
        const date = (typeof tournamentBookingDate === 'function')
            ? tournamentBookingDate()
            : (window.__scheduleDate || '');

        renderTournamentPanel({
            block: 'tournamentInfoBlock',
            list:  'tnList',
            empty: 'tnEmpty',
            count: 'tnCount',
            price: 'tnPrice',
            link:  'tnLink',
        }, tournamentId, date, false);
    }

    // Сброс блока «Тип брони» в окне СОЗДАНИЯ. Без него тип, выбранный в прошлый
    // раз, оставляет форму в чужом виде: поля клиента скрыты и не required, а
    // submit-обработчик уже требует имя и телефон — форма молча не отправляется.
    function resetBookingTypeSelection() {
        const input = document.getElementById('bookingTypeInput');
        if (input) input.value = '';
        document.querySelectorAll('#bookingTypeButtons .bt-btn').forEach(b => b.classList.remove('active'));
        // Свежая бронь — обычная: показываем поля клиента/оплаты, прячем блоки
        // группы и турнира.
        document.querySelectorAll('.js-hide-for-group').forEach(el => el.style.display = '');
        document.querySelectorAll('.js-show-for-group').forEach(el => el.style.display = 'none');
        const groupWrap = document.getElementById('bookGroupSelectWrap');
        if (groupWrap) groupWrap.style.display = 'none';
        const groupSelect = document.getElementById('bookGroupSelect');
        if (groupSelect) groupSelect.value = '';
        if (typeof renderGroupMembers === 'function') renderGroupMembers('');
        const tnWrap = document.getElementById('bookTournamentSelectWrap');
        if (tnWrap) tnWrap.style.display = 'none';
        const tnSelect = document.getElementById('bookTournamentSelect');
        if (tnSelect) tnSelect.value = '';
        renderTournamentInfo('');
        ['bookClientName', 'bookClientPhone'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.required = true;
        });
        if (typeof resetBookInventory === 'function') resetBookInventory();
    }

    // Была ли открытая бронь турнирной ДО правки. У легаси-броней (тип «Турнир»
    // проставлен, а турнир не привязан — так работала кнопка до этой фичи)
    // требовать выбор турнира нельзя: иначе бронь не сохранить вообще.
    window.__editBookingWasTournament = false;
    function tournamentRequiredInEdit() {
        return !window.__editBookingWasTournament;
    }

    // Для турнирной брони в окне редактирования прячем поля клиента и оплаты —
    // как в окне создания: цену задаёт турнир, игроки платят взносы отдельно.
    function applyEditTournamentVisibility(isTournament) {
        document.querySelectorAll('.js-edit-hide-for-group').forEach(function (el) {
            el.style.display = isTournament ? 'none' : '';
        });
        const phone = document.getElementById('editClientPhone');
        if (phone) { phone.required = !isTournament; }
        const name = document.getElementById('editClientName');
        if (name) { name.readOnly = isTournament; }
        const label = document.getElementById('editClientLabel');
        if (label && isTournament) { label.textContent = 'Турнир'; }
        const block = document.getElementById('editTournamentBlock');
        if (block) block.style.display = isTournament ? 'block' : 'none';
        if (isTournament) { renderEditTournamentPrice(); }
    }

    // Панель турнира в окне РЕДАКТИРОВАНИЯ — тот же вид, что и при создании.
    function renderEditTournamentPrice() {
        const sel = document.getElementById('editTournamentSelect');
        if (!sel) return;

        // Дата открытой брони — её кладёт openViewModal. Делитель считается по ней,
        // а не по дате страницы: бронь может быть с другого дня (панель заявок).
        renderTournamentPanel({
            block: 'editTournamentInfoBlock',
            list:  'editTnList',
            empty: 'editTnEmpty',
            count: 'editTnCount',
            price: 'editTnPrice',
            link:  'editTnLink',
        }, sel.value, window.__editBookingDate || '', true);
    }
</script>
