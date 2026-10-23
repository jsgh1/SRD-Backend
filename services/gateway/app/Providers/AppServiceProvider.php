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
        RateLimiter::for('srd-global', static function (Request $request) {
            $ip = $request->ip() ?? 'unknown';
            $credential = $request->session()->get('credential');
            if (! is_string($credential) || $credential === '') {
                return Limit::perMinute(120)->by('guest:'.$ip);
            }

            return [
                Limit::perMinute(600)->by('session:'.hash('sha256', $credential)),
                Limit::perMinute(6000)->by('ip:'.$ip),
            ];
        });

        RateLimiter::for('srd-export', static function (Request $request) {
            $ip = $request->ip() ?? 'unknown';
            $credential = $request->session()->get('credential');
            if (! is_string($credential) || $credential === '') {
                return Limit::perMinute(5)->by('guest:'.$ip);
            }

            return Limit::perMinute(5)->by('session:'.hash('sha256', $credential));
        });
    }
}
