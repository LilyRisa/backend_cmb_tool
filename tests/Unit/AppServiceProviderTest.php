<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    public function test_guard_throws_in_production_when_webhook_token_is_empty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEPAY_WEBHOOK_TOKEN must be set in production');

        AppServiceProvider::guardSepayWebhookToken(true, '');
    }

    public function test_guard_throws_in_production_when_webhook_token_is_null(): void
    {
        $this->expectException(\RuntimeException::class);

        AppServiceProvider::guardSepayWebhookToken(true, null);
    }

    public function test_guard_does_not_throw_in_production_when_webhook_token_is_set(): void
    {
        AppServiceProvider::guardSepayWebhookToken(true, 'some-secret-token');

        $this->addToAssertionCount(1);
    }

    public function test_guard_does_not_throw_outside_production_even_when_empty(): void
    {
        AppServiceProvider::guardSepayWebhookToken(false, '');

        $this->addToAssertionCount(1);
    }
}
