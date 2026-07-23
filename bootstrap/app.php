<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
		api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware) {
		$middleware->validateCsrfTokens(except: [
			'api/telegram/webhook',
		]);
		
		$middleware->alias([
			'role' => \App\Http\Middleware\RoleMiddleware::class,
			'telegram.miniapp' => \App\Http\Middleware\TelegramMiniAppAuth::class,
			'club.feature' => \App\Http\Middleware\CheckClubFeature::class,
		]);
	})
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('tournaments:process-moderation')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('tournaments:send-reminders')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('bookings:send-reminders')
            ->everyMinute()
            ->withoutOverlapping();

        // Авто-проведение групповых занятий (клубы с включённой настройкой).
        $schedule->command('group-sessions:auto-conduct')
            ->everyTenMinutes()
            ->withoutOverlapping();

        // Ежедневный бэкап БД + загруженных файлов (локально 7 копий + облако).
        $schedule->command('backup:run')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->runInBackground();
    })->create();