{{-- Инструкция: Как проводить Американо. Скрины: public/help/conduct-americano/ --}}

<div class="ha-note">
    <div class="ic">i</div>
    <div>Формат <b>Американо</b>: участники играют в парах, каждый раунд — новая пара. Очки считаются лично каждому игроку. Турнир должен быть создан заранее (см. «Как создать турнир»).</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">1</div><h2 class="ha-step-t">Добавьте участников</h2></div>
    <p>Игроки записываются сами из приложения, либо вы добавляете их вручную. Для проверки/тренировки есть кнопка <span class="ha-kbd">Добавить тестовых игроков</span> — она заполнит турнир до нужного числа.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-americano/participants.png') }}" alt="Участники турнира"></div>
    <div class="ha-cap">Счётчик <b>Участников 8/8</b> — турнир заполнен и готов к старту.</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">2</div><h2 class="ha-step-t">Запустите турнир</h2></div>
    <p>Нажмите <span class="ha-kbd">Начать турнир</span> — группы и первый раунд сформируются автоматически, статус сменится на <b>«В процессе»</b>.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-americano/rounds.png') }}" alt="Раунды и рассадка по кортам"></div>
    <ul class="ha-list">
        <li><b>Таблица лидеров</b> — общий зачёт (пока по нулям).</li>
        <li><b>Раунд 1 · Идёт</b> — рассадка по кортам: на каждом корте две пары.</li>
        <li>Зелёная кнопка с карандашом у матча — открыть ввод счёта.</li>
    </ul>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">3</div><h2 class="ha-step-t">Вводите счёт матчей</h2></div>
    <p>Нажмите на кнопку счёта у матча — откроется окно <b>«Ввод счёта»</b>. Впишите очки каждой пары и нажмите <span class="ha-kbd">Сохранить</span>.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-americano/score-modal.png') }}" alt="Окно ввода счёта"></div>
    <div class="ha-cap">Счёт можно переписать позже — у сыгранного матча появляется кнопка редактирования.</div>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">4</div><h2 class="ha-step-t">Следите за таблицей и открывайте раунды</h2></div>
    <p>После сохранения счёта таблица лидеров сразу пересчитывается. Когда все матчи раунда сыграны — нажмите <span class="ha-kbd">Сгенерировать раунд</span>, чтобы открыть следующий (пары переставятся по результатам).</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-americano/leaderboard.png') }}" alt="Таблица лидеров со счётом"></div>
    <ul class="ha-list">
        <li><b>В / П / З</b> — выигрыши / поражения / забитые очки, <b>ПР</b> — пропущенные, <b>ОЧКИ</b> — сумма в зачёт.</li>
        <li>Раунд помечается <b>«Завершён»</b>, когда введены все его счета.</li>
    </ul>
</div>

<div class="ha-step">
    <div class="ha-step-h"><div class="ha-step-n">5</div><h2 class="ha-step-t">Завершите турнир</h2></div>
    <p>Когда сыграны все раунды — нажмите <span class="ha-kbd">Завершить турнир</span>. Статус станет <b>«Завершён»</b>, определятся места, а рейтинг участников пересчитается автоматически.</p>
    <div class="ha-shot"><img src="{{ asset('help/conduct-americano/finished.png') }}" alt="Завершённый турнир — итоговая таблица"></div>
    <ul class="ha-list">
        <li>Призовые места (1–3) подсвечены в таблице.</li>
        <li>Рейтинг применяется <b>один раз</b> при завершении. Действие необратимо.</li>
    </ul>
</div>
