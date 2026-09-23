<?php

namespace App\Providers;

use App\Mail\Transport\GmailApiTransport;
use App\Services\Google\GmailTokenProvider;
use Illuminate\Support\Facades\Mail;
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
        $this->registerGmailTransport();
    }

    /**
     * Makes MAIL_MAILER=gmail available.
     *
     * Registered as a mailer rather than replacing the default so the log and
     * array drivers stay usable in tests and local work.
     */
    private function registerGmailTransport(): void
    {
        Mail::extend('gmail', function (array $config = []) {
            return new GmailApiTransport($this->app->make(GmailTokenProvider::class));
        });
    }
}
