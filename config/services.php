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
	'kazinfoteh' => [
		'scheme' => env('KAZINFOTEH_SCHEME', 'http'),
		'host' => env('KAZINFOTEH_HOST', 'kazinfoteh.org'),
		'port' => env('KAZINFOTEH_PORT', '9507'),
		'username' => env('KAZINFOTEH_USERNAME'),
		'password' => env('KAZINFOTEH_PASSWORD'),
		// Имя отправителя (альфа-имя), зарегистрированное у оператора.
		'originator' => env('KAZINFOTEH_ORIGINATOR', 'INFO_KAZ'),
	],

];
