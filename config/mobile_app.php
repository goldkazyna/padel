<?php

return [
    // Минимальная версия (ниже — принудительное обновление)
    'min_version' => env('APP_MIN_VERSION', '1.0.0'),

    // Актуальная версия в магазинах
    'latest_version' => env('APP_LATEST_VERSION', '1.1.0'),

    // Принудительное обновление (блокирует приложение)
    'force_update' => env('APP_FORCE_UPDATE', false),

    // Ссылки на магазины
    'store_url_ios' => env('APP_STORE_URL_IOS', 'https://apps.apple.com/app/padel-kz/id6740451498'),
    'store_url_android' => env('APP_STORE_URL_ANDROID', 'https://play.google.com/store/apps/details?id=com.padelkz.app'),
];
