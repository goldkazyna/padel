<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
	
	'telegram' => [
		'bot_token' => env('TELEGRAM_BOT_TOKEN'),
		'bot_username' => env('TELEGRAM_BOT_USERNAME', 'add_app_bot'),
		'channel_id' => env('TELEGRAM_CHANNEL_ID'),
	],

	'telegram_mobile' => [
		'bot_token' => env('TELEGRAM_MOBILE_BOT_TOKEN'),
		'bot_username' => env('TELEGRAM_MOBILE_BOT_USERNAME'),
	],

	'google' => [
		// OAuth Web client ID (берётся из Firebase → Project settings → OAuth 2.0 Client IDs → Web client).
		// Android-приложение выдаёт id_token с aud = Web client ID, поэтому сравниваем именно с ним.
		'client_id' => env('GOOGLE_CLIENT_ID'),
	],

	'apple' => [
		// Bundle ID iOS-приложения. Apple identity_token имеет aud = этому значению.
		// Для нескольких клиентов можно передать через запятую: "com.a.b,com.c.d".
		'bundle_id' => env('APPLE_BUNDLE_ID', 'com.padelkz.app'),
	],

	// SMS-шлюз KazInfoTeh (HTTP). Документация: http://docs.kazinfoteh.kz/protocols/http/outbox/
	// Платёжный шлюз Plexy (developer.plexy.money). Ключи у каждого клуба свои
	// (в clubs.plexy_*), эти env — дефолт/фолбэк, если у клуба не заданы.
	'plexy' => [
		'base_url' => env('PLEXY_BASE_URL', 'https://api.plexypay.com'),
		'key' => env('PLEXY_API_KEY'),
		'merchant_id' => env('PLEXY_MERCHANT_ID'),
		'webhook_secret' => env('PLEXY_WEBHOOK_SECRET'),
	],

	'kazinfoteh' => [
		'scheme' => env('KAZINFOTEH_SCHEME', 'http'),
		'host' => env('KAZINFOTEH_HOST', 'kazinfoteh.org'),
		'port' => env('KAZINFOTEH_PORT', '9507'),
		'username' => env('KAZINFOTEH_USERNAME'),
		'password' => env('KAZINFOTEH_PASSWORD'),
		// Имя отправителя (альфа-имя), зарегистрированное у оператора.
		'originator' => env('KAZINFOTEH_ORIGINATOR', 'KiT_Notify'),
	],

	// WhatsApp через Whapi.Cloud: приём входящих вебхуком и ручная отправка.
	// Секрет вебхука живёт в адресе — Whapi не умеет слать свои заголовки.
	'whapi' => [
		'url' => env('WHAPI_URL', 'https://gate.whapi.cloud/'),
		'token' => env('WHAPI_TOKEN'),
		'channel' => env('WHAPI_CHANNEL'),
		'webhook_secret' => env('WHAPI_WEBHOOK_SECRET'),
		// Канал пока один; чей это WhatsApp — задаём явно.
		'club_id' => env('WHAPI_CLUB_ID'),
		// Рабочее окно клуба: ночное молчание не считаем просрочкой.
		'work_from' => env('WHAPI_WORK_FROM', '09:00'),
		'work_to' => env('WHAPI_WORK_TO', '23:00'),
		'sla_minutes' => env('WHAPI_SLA_MINUTES', 5),
		// Разбор дня переписки: модель посильнее, чем для разбора турнира.
		'analysis_model' => env('WHAPI_ANALYSIS_MODEL', 'claude-sonnet-5'),
	],

	// Claude API (Anthropic) — AI-разбор выступления игрока в турнире.
	'anthropic' => [
		'key' => env('ANTHROPIC_API_KEY'),
		'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
		'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
	],

];
