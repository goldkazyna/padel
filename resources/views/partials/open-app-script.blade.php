{{--
    Открыть приложение по диплинку, а если его нет — увести в магазин.

    Ожидает три переменные: $deepLink, $storeAppUrl, $storeWebUrl.

    Порядок важен. Схема market:// и itms-apps:// открывают само приложение
    магазина; обычная ссылка при переходе из JS остаётся в браузере, и человек
    видит веб-страницу Play Market вместо магазина. Обычная ссылка нужна
    последней — на случай, если схему никто не обработал.
--}}
<script>
    (function () {
        var ua = navigator.userAgent || navigator.vendor || window.opera;
        var isAndroid = /android/i.test(ua);
        var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;

        // Только на мобилках пробуем deep link
        if (!isAndroid && !isIOS) return;

        var deepLink = {!! json_encode($deepLink, JSON_UNESCAPED_SLASHES) !!};
        var storeAppUrl = {!! json_encode($storeAppUrl, JSON_UNESCAPED_SLASHES) !!};
        var storeWebUrl = {!! json_encode($storeWebUrl, JSON_UNESCAPED_SLASHES) !!};
        var timers = [];

        function cancel() {
            timers.forEach(clearTimeout);
            timers = [];
        }

        // Страница ушла в фон (= что-то открылось) — дальше не ведём
        window.addEventListener('blur', cancel);
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) cancel();
        });

        timers.push(setTimeout(function () {
            window.location.href = storeAppUrl;
        }, 1800));

        timers.push(setTimeout(function () {
            window.location.href = storeWebUrl;
        }, 3400));

        window.location.href = deepLink;
    })();
</script>
