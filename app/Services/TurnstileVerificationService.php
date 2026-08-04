<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerificationService
{
    /**
     * Verify a Cloudflare Turnstile token against the configured site/secret keys.
     *
     * Returns null when the token is valid, or when verification is a safe
     * no-op (unconfigured / partially configured / Cloudflare API error —
     * all fail open so a captcha outage never blocks login). Returns a
     * user-facing Vietnamese error message string when the token is missing
     * or genuinely invalid.
     */
    public static function verify(?string $token, string $ip): ?string
    {
        $siteKey = config('services.cloudflare_turnstile.site_key');
        $secretKey = config('services.cloudflare_turnstile.secret_key');

        $hasSiteKey = !empty($siteKey);
        $hasSecretKey = !empty($secretKey);

        // Both keys must be set together, or neither. site_key drives whether the
        // frontend renders the widget at all; secret_key drives whether this
        // method requires a token. If only secret_key is set, no widget ever
        // renders, no token can ever arrive, and every login would fail forever —
        // a safe no-op is the only sane behavior for that split-brain state.
        if (!$hasSiteKey || !$hasSecretKey) {
            if ($hasSiteKey !== $hasSecretKey) {
                Log::warning('Cloudflare Turnstile is partially configured: CLOUDFLARE_CAPTCHA_SITE_KEY and CLOUDFLARE_CAPTCHA_SECRET_KEY must both be set (or both left empty). Verification is disabled until both are set.');
            }

            return null;
        }

        if (empty($token)) {
            return 'Vui lòng xác thực captcha';
        }

        try {
            $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secretKey,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            if (!$response->successful()) {
                Log::error('Turnstile API returned a non-successful response', [
                    'status' => $response->status(),
                ]);
                // Allow through on API error to not block users
                return null;
            }

            $result = $response->json();

            if (!($result['success'] ?? false)) {
                Log::warning('Turnstile verification failed', [
                    'ip' => $ip,
                    'error_codes' => $result['error-codes'] ?? [],
                ]);

                return 'Xác thực captcha không hợp lệ. Vui lòng thử lại.';
            }
        } catch (\Exception $e) {
            Log::error('Turnstile API error: ' . $e->getMessage());
            // Allow through on API error to not block users
            return null;
        }

        return null;
    }
}
