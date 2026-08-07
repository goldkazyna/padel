{{-- Общий JS турнирной брони: расчёт цены и список участников.
     Подключается в дневном и недельном расписании. --}}
<script>
    // Информация о выбранном турнире в окне создания: расчёт цены и список оплативших.
    function renderTournamentInfo(tournamentId) {
        const block = document.getElementById('tournamentInfoBlock');
        const list = document.getElementById('tnList');
        const empty = document.getElementById('tnEmpty');
        const count = document.getElementById('tnCount');
        const priceEl = document.getElementById('tnPrice');
        const link = document.getElementById('tnLink');
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
        // Дату даёт вьюха: в дневном виде она одна на страницу, в недельном —
        // из выбранного слота. Функция tournamentBookingDate() определена во вьюхе.
        const date = (typeof tournamentBookingDate === 'function')
            ? tournamentBookingDate()
            : (window.__scheduleDate || '');
        // Делитель — уже существующие брони турнира на эту дату плюс создаваемая.
        const existing = (data.bookings_by_date && data.bookings_by_date[date]) || 0;
        const divisor = existing + 1;
        const share = divisor > 0 ? Math.round(data.total / divisor) : 0;

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

    // Расчёт под селектом в окне редактирования.
    function renderEditTournamentPrice() {
        const sel = document.getElementById('editTournamentSelect');
        const el = document.getElementById('editTnPrice');
        if (!sel || !el) return;
        const data = (window.__tournaments && window.__tournaments[sel.value]) || null;
        if (!data) { el.innerHTML = ''; return; }
        const fmt = n => new Intl.NumberFormat('ru-RU').format(n);
        el.innerHTML = data.price
            ? (data.paid_count + ' оплативших × ' + fmt(data.price) + ' = <b>' + fmt(data.total) + ' ₸</b> на все корты турнира в этот день')
            : 'У турнира не указана цена';
    }
</script>
