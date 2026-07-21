{{-- Инструкция: Как проводить командный турнир. Скрины: public/help/conduct-team/ --}}

<div class="ha-note">
    <div class="ic">i</div>
    <div>В <b>командном</b> турнире играют постоянные <b>пары</b>. Пары делятся на группы, играют круговой этап, затем лучшие выходят в плей-офф. Турнир создаётся заранее с типом «Командный» (см. «Как создать турнир»).</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">1</div><h2 class="ha-step-t">Соберите пары</h2></div>
    <p>Пары записываются сами из приложения (игрок выбирает партнёра), либо вы добавляете их вручную. Для проверки есть кнопка <span class="ha-kbd">Добавить тестовые пары</span>.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-team/teams.png') }}" alt="Зарегистрированные пары"></div>
    <ul class="ha-list">
        <li><b>Зарегистрированные пары</b> — заполненные слоты (например 4/4).</li>
        <li>Если есть <b>Заявки на модерации</b> — подтвердите пары кнопкой «✓».</li>
    </ul>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">2</div><h2 class="ha-step-t">Распределите пары по группам и запустите</h2></div>
    <p>Откройте <b>Распределение пар</b>. Разложите пары по группам — кнопками <span class="ha-kbd">A</span>/<span class="ha-kbd">B</span> у каждой пары или сразу <span class="ha-kbd">Авто (по рейтингу)</span>. Когда все распределены — <span class="ha-kbd">Начать турнир</span>.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-team/distribute.png') }}" alt="Распределение пар по группам"></div>
    <div class="ha-cap">В каждой группе должно быть одинаковое число пар — счётчик сверху подскажет.</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">3</div><h2 class="ha-step-t">Групповой этап — вводите счёт</h2></div>
    <p>Каждая пара играет с остальными в своей группе. У матча нажмите <span class="ha-kbd">Ввести счёт</span>, впишите очки и сохраните. Таблица группы пересчитается автоматически.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-team/score-modal.png') }}" alt="Ввод счёта группового матча"></div>
    <ul class="ha-list">
        <li>В таблице: <b>И</b> — игры, <b>В/П</b> — победы/поражения, <b>ЗМ/ПМ</b> — забитые/пропущенные, <b>+/−</b> разница, <b>О</b> — очки.</li>
        <li>Группа помечается <b>«Завершена»</b>, когда сыграны все её матчи.</li>
    </ul>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">4</div><h2 class="ha-step-t">Плей-офф</h2></div>
    <p>Когда групповой этап завершён, формируется <b>плей-офф</b>: лучшие пары групп выходят в финал (и матч за 3-е место, если включён). Счёт вводится так же — кнопкой <span class="ha-kbd">Ввести счёт</span>.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-team/playoff.png') }}" alt="Сетка плей-офф"></div>
    <div class="ha-cap">В плей-офф ничья невозможна — счёт пар должен отличаться.</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">5</div><h2 class="ha-step-t">Завершите турнир</h2></div>
    <p>После финала нажмите <span class="ha-kbd">Завершить турнир</span>. Определится победитель, статус станет <b>«Завершён»</b>, рейтинг пар пересчитается автоматически.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-team/finished.png') }}" alt="Итоги командного турнира — победитель"></div>
    <ul class="ha-list">
        <li>Внизу — баннер <b>«Победитель турнира»</b>.</li>
        <li>Рейтинг применяется <b>один раз</b> при завершении. Действие необратимо.</li>
    </ul>
</div>
