<?php

namespace App\Providers;

use App\Services\Tse\TseClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TseClient::class, fn (): TseClient => TseClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Login attempts: by address, and by account so that guessing the
     * password of one admin from many addresses is slowed down as well.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinutes(10, 10)->by('email:'.mb_strtolower($request->string('email')->trim()->toString())),
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
