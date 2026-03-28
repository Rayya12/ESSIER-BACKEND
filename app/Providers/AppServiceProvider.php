<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use app\Services\GeminiService;
use app\Services\PdfParserService;
use Smalot\PdfParser\Parser;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GeminiService::class);

        $this->app->singleton(PdfParserService::class, function ($app) {
            return new PdfParserService(
                parser: new Parser(),
                gemini: $app->make(GeminiService::class),
        );
        });
    }
    

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
