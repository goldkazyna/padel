<?php

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return 'https://padel-p.kz/reset-password?token=' . $token . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });

        // @phone($phone) — телефон как есть или «скрыт» (если у клуба hide_phones)
        Blade::directive('phone', function ($expression) {
            return "<?php echo \App\Support\PhoneVisibility::display($expression); ?>";
        });

        // @phoneFmt($phone) — то же, но с форматированием +7 700 ...
        Blade::directive('phoneFmt', function ($expression) {
            return "<?php echo \App\Support\PhoneVisibility::display($expression, true); ?>";
        });

        // Лог веб-входов: кто/когда/устройство. Пишем при успешном входе через
        // веб-гвард (мобильный API/токены сюда не попадают). device_id — UUID
        // из долгой cookie, чтобы отличать устройства (шаринг логина).
        Event::listen(Login::class, function (Login $event) {
            if ($event->guard !== 'web') {
                return;
            }
            $request = request();
            if (!$request) {
                return;
            }

            $deviceId = $request->cookie('device_id');
            if (empty($deviceId)) {
                $deviceId = (string) Str::uuid();
                // Кладём в cookie на 2 года, чтобы устройство узнавалось при следующих входах.
                Cookie::queue('device_id', $deviceId, 60 * 24 * 365 * 2);
            }

            \App\Models\LoginLog::create([
                'user_id' => $event->user->getAuthIdentifier(),
                'device_id' => is_string($deviceId) ? substr($deviceId, 0, 64) : null,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            ]);
        });
    }
}
