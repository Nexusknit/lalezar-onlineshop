<?php

namespace App\Providers;

use App\Support\Phone\IranPhoneNormalizer;
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
            $phone = IranPhoneNormalizer::normalize($request->input('phone')) ?? preg_replace('/\D+/', '', (string) $request->input('phone', ''));
            $ip = $request->ip() ?: 'unknown';
            $phoneKey = $phone !== '' ? $phone : 'no-phone';

            return Limit::perMinute(5)->by("auth-login:{$ip}:{$phoneKey}");
        });

        RateLimiter::for('auth-verify', static function (Request $request) {
            $phone = IranPhoneNormalizer::normalize($request->input('phone')) ?? preg_replace('/\D+/', '', (string) $request->input('phone', ''));
            $ip = $request->ip() ?: 'unknown';
            $phoneKey = $phone !== '' ? $phone : 'no-phone';

            return Limit::perMinute(10)->by("auth-verify:{$ip}:{$phoneKey}");
        });

        RateLimiter::for('user-api', static function (Request $request) {
            $key = $request->user()?->id
                ? 'user:'.$request->user()->id
                : 'ip:'.($request->ip() ?: 'unknown');

            return Limit::perMinute(120)->by($key);
        });

        RateLimiter::for('admin-api', static function (Request $request) {
            $key = $request->user()?->id
                ? 'admin:'.$request->user()->id
                : 'ip:'.($request->ip() ?: 'unknown');

            return Limit::perMinute(120)->by($key);
        });
    }
}
