<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Blade;
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
    }
}
