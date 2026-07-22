{{-- Инструкция: Клубные карты. Скрины: public/help/club-cards/ --}}

<div class="ha-note">
    <div class="ic">i</div>
    <div><b>Клубная карта</b> — абонемент клиента на часы корта (или посещения). Сначала создаётся <b>тип карты</b>, затем карта <b>выпускается</b> конкретному клиенту, а часы <b>списываются</b> после броней.</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">1</div><h2 class="ha-step-t">Создайте тип карты</h2></div>
    <p>В разделе <span class="ha-kbd">Клубные карты</span> нажмите <span class="ha-kbd">+ Создать тип карты</span>.</p>
    <div class="ha-shot"><img src="{{ asset('help/club-cards/create-type.png') }}" alt="Создание типа карты"></div>
    <ul class="ha-list">
        <li><b>Название</b> и <b>Префикс номера</b> — карты нумеруются автоматически (напр. VIP000001).</li>
        <li><b>Вид карты</b> — например «Посещения корта».</li>
        <li><b>Номинал</b> (число часов), <b>Стоимость</b>, <b>Срок действия</b> (можно бессрочно).</li>
    </ul>
    <div class="ha-cap">На главной странице видны все типы и выпущенные карты по клиентам с остатком и статусом.</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">2</div><h2 class="ha-step-t">Выпустите карту клиенту</h2></div>
    <p>Откройте клиента (раздел «Клиенты») и в блоке «Клубные карты» нажмите <span class="ha-kbd">+ Привязать</span>.</p>
    <div class="ha-shot"><img src="{{ asset('help/club-cards/issue.png') }}" alt="Выпуск карты клиенту"></div>
    <ul class="ha-list">
        <li><b>Тип карты</b> — выберите из созданных.</li>
        <li><b>Остаток (число часов)</b> — пусто = номинал типа.</li>
        <li><b>Действует до</b> — по желанию (по умолчанию бессрочно).</li>
    </ul>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">3</div><h2 class="ha-step-t">Списание часов</h2></div>
    <p>Часы с карт-счётчиков списываются <b>после завершённой брони</b>. Подтверждение — на странице <span class="ha-kbd">К списанию</span>.</p>
    <div class="ha-shot"><img src="{{ asset('help/club-cards/pending.png') }}" alt="К списанию — подтверждение списаний"></div>
    <ul class="ha-list">
        <li>Здесь показаны завершённые брони, оплаченные картой-счётчиком.</li>
        <li><b>Подтвердите</b> списание часов или <b>пропустите</b> бронь, если списывать не нужно.</li>
        <li>Все движения по картам — в разделе <b>Журнал</b>.</li>
    </ul>
</div>
