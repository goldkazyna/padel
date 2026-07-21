{{-- Инструкция: Проведение занятия. Скрины: public/help/conduct-session/ --}}

<div class="ha-note">
    <div class="ic">i</div>
    <div>Занятия ведутся в разделе <b>«Журнал занятий»</b>. При проведении система списывает по одному занятию с абонемента каждого пришедшего участника.</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">1</div><h2 class="ha-step-t">Откройте Журнал занятий</h2></div>
    <p>Это сетка <b>время × дата</b> по неделям. Сверху — фильтры по группе и статусу, переключение недель и кнопка <span class="ha-kbd">+ Создать</span>.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-session/journal.png') }}" alt="Журнал занятий"></div>
    <div class="ha-cap">Цвет занятия: серый — запланировано, зелёный — можно провести / проведено, красный — отменено.</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">2</div><h2 class="ha-step-t">Создайте занятие</h2></div>
    <p>Нажмите <span class="ha-kbd">+ Создать</span> (или на пустую клетку сетки) и заполните.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-session/create.png') }}" alt="Создание занятия"></div>
    <ul class="ha-list">
        <li><b>Группа</b> и <b>Корт</b> — обязательны.</li>
        <li><b>Дата</b> и <b>Начало</b>; <b>слотов</b> — длительность (1 слот ≈ 60 мин).</li>
        <li><b>Тренер</b> — из группы или другой (замена).</li>
    </ul>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">3</div><h2 class="ha-step-t">Отметьте посещаемость и проведите</h2></div>
    <p>Откройте занятие. У каждого участника выберите статус и нажмите <span class="ha-kbd">Провести занятие</span>.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-session/conduct.png') }}" alt="Отметка посещаемости"></div>
    <ul class="ha-list">
        <li><b>Списать</b> — был на занятии, спишется 1 занятие с абонемента.</li>
        <li><b>Пробное</b> — пробное занятие (не списывается с пакета).</li>
        <li><b>Не был</b> — пропуск, ничего не списывается.</li>
        <li><b>Пробные гости</b> — можно добавить разового гостя за деньги.</li>
        <li>Блок <b>«При проведении произойдёт»</b> заранее показывает, что и сколько спишется.</li>
    </ul>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">4</div><h2 class="ha-step-t">Результат</h2></div>
    <p>После проведения занятие помечается <b>«Проведено»</b>, а у участника видно списание.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-session/result.png') }}" alt="Проведённое занятие"></div>
    <div class="ha-cap">Отменить занятие можно кнопкой «Отменить» — тогда ничего не списывается.</div>
</div>
