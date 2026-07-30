<?php

namespace App\Services;

use Illuminate\Support\Str;

class SePayService
{
    public static function generateTransactionCode(int $userId, string $kind = ''): string
    {
        // Str::random(6) adds entropy beyond second-resolution time() so that two
        // topup/subscribe requests from the same user within the same second don't
        // collide on the transaction_code UNIQUE index.
        $code = config('sepay.pattern', 'CMB') . $kind . $userId . time() . Str::random(6);
        return preg_replace('/[^A-Za-z0-9]/', '', $code);
    }

    public static function bankInfo(int $amount, string $code): array
    {
        return [
            'account_number' => (string) config('sepay.account_number', ''),
            'account_name' => (string) config('sepay.account_name', ''),
            'bank_name' => (string) config('sepay.bank_name', ''),
            'amount' => $amount,
            'content' => $code,
        ];
    }

    public static function qrUrl(int $amount, string $code): string
    {
        $query = http_build_query([
            'acc' => preg_replace('/[^A-Za-z0-9]/', '', (string) config('sepay.account_number', '')),
            'bank' => (string) config('sepay.bank_name', ''),
            'amount' => $amount,
            'des' => $code,
        ]);
        return 'https://qr.sepay.vn/img?' . $query;
    }

    public static function hasBankConfig(): bool
    {
        return !empty(config('sepay.account_number'))
            && !empty(config('sepay.account_name'))
            && !empty(config('sepay.bank_name'));
    }
}
