<?php

namespace Tests\Unit;

use App\Services\SePayService;
use Tests\TestCase;

class SePayServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sepay.pattern' => 'CMB',
            'sepay.account_number' => '0123456789',
            'sepay.account_name' => 'CONG TY TNHH CMB',
            'sepay.bank_name' => 'MBBank',
        ]);
    }

    public function test_generate_transaction_code_is_alphanumeric_and_contains_pattern(): void
    {
        $code = SePayService::generateTransactionCode(42, 'SUB');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $code);
        $this->assertStringStartsWith('CMBSUB42', $code);
    }

    public function test_generate_transaction_code_without_kind(): void
    {
        $code = SePayService::generateTransactionCode(7);

        $this->assertStringStartsWith('CMB7', $code);
    }

    public function test_bank_info_returns_configured_account_details(): void
    {
        $info = SePayService::bankInfo(50000, 'CMB123');

        $this->assertEquals('0123456789', $info['account_number']);
        $this->assertEquals('MBBank', $info['bank_name']);
        $this->assertEquals(50000, $info['amount']);
        $this->assertEquals('CMB123', $info['content']);
    }

    public function test_qr_url_contains_amount_and_code(): void
    {
        $url = SePayService::qrUrl(50000, 'CMB123');

        $this->assertStringStartsWith('https://qr.sepay.vn/img?', $url);
        $this->assertStringContainsString('amount=50000', $url);
        $this->assertStringContainsString('des=CMB123', $url);
    }

    public function test_has_bank_config_true_when_all_three_set(): void
    {
        $this->assertTrue(SePayService::hasBankConfig());
    }

    public function test_has_bank_config_false_when_missing(): void
    {
        config(['sepay.account_number' => null]);

        $this->assertFalse(SePayService::hasBankConfig());
    }
}
