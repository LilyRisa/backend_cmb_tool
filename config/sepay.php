<?php

return [
    'webhook_token' => env('SEPAY_WEBHOOK_TOKEN'),
    'pattern' => env('SEPAY_MATCH_PATTERN', 'CMB'),
    'account_number' => env('SEPAY_ACCOUNT_NUMBER'),
    'account_name' => env('SEPAY_ACCOUNT_NAME'),
    'bank_name' => env('SEPAY_BANK_NAME'),
];
