<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Fortify используется только для входа/выхода (features = [] в
 * config/fortify.php). Страница логина — своя Blade-вью. Лимитер
 * 'login' (его имя задано в config/fortify.php → limiters.login)
 * Fortify сам не регистрирует — определяем штатный: 5 попыток в минуту
 * на пару email + IP.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));

        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });
    }
}
