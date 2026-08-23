{{-- Поднимает модалки ввода счёта на верхний уровень страницы.

     Карточки завершённых и будущих раундов приглушены (`opacity: .6` / `.4`),
     а элемент с прозрачностью меньше единицы создаёт свой контекст наложения.
     Модалка лежит внутри такой карточки и не может подняться выше затемнения,
     которое Bootstrap кладёт в конец `body`: в итоге окно видно сквозь тёмный
     слой и по нему нельзя кликнуть.

     Переносим модалки в `body` — там они снова оказываются над затемнением.
     Делать это нужно один раз при загрузке, до первого показа. --}}

<script>
(function () {
    function lift() {
        document.querySelectorAll('.modal').forEach(function (modal) {
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', lift);
    } else {
        lift();
    }
})();
</script>
