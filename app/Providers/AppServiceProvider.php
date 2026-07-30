<?php

namespace App\Providers;

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
        static::guardSepayWebhookToken($this->app->environment('production'), config('sepay.webhook_token'));
    }

    /**
     * Fail fast if running in production without a configured SePay webhook token.
     *
     * The sepayvn/laravel-sepay package's own SePayController::webhook() does
     * `throw_if(config('sepay.webhook_token') && $token !== config('sepay.webhook_token'), ...)` —
     * when the config value is empty, that check is skipped entirely and the webhook
     * accepts unauthenticated requests, letting anyone self-credit or self-activate
     * Premium without a real bank transfer. Extracted as a static method (rather than
     * inlined in boot()) so it can be unit-tested directly without having to boot the
     * whole application under APP_ENV=production.
     */
    public static function guardSepayWebhookToken(bool $isProduction, $webhookToken): void
    {
        if ($isProduction && empty($webhookToken)) {
            throw new \RuntimeException(
                'SEPAY_WEBHOOK_TOKEN must be set in production — an empty value disables webhook authentication.'
            );
        }
    }
}
