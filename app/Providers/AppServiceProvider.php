<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('auth-login', static function (Request $request) {
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
            $ip = $request->ip() ?: 'unknown';
            $phoneKey = $phone !== '' ? $phone : 'no-phone';

            return Limit::perMinute(5)->by("auth-login:{$ip}:{$phoneKey}");
        });

        RateLimiter::for('auth-verify', static function (Request $request) {
            $phone = preg_replace('/\D+/', '', (string) $request->input('phone', ''));
            $ip = $request->ip() ?: 'unknown';
            $phoneKey = $phone !== '' ? $phone : 'no-phone';

            return Limit::perMinute(10)->by("auth-verify:{$ip}:{$phoneKey}");
        });
    }
}
