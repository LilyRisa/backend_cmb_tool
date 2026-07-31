<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenMaxService
{
    protected string $baseUrl = 'https://api.genmax.io';
    protected int $charsPerMinute;

    protected const VOICE_SETTINGS_MAP = [
        'minimax' => ['speed', 'pitch', 'vol'],
        'elevenlabs' => ['stability', 'similarity_boost', 'style', 'use_speaker_boost'],
    ];

    private const TTS_CACHE_TTL = 5 * 60 * 60;

    public function __construct()
    {
        $this->charsPerMinute = SystemSetting::getCharsPerMinute();
    }

    protected function getApiKey(): string
    {
        $key = SystemSetting::getGenMaxApiKey();

        if (!$key) {
            throw new \RuntimeException('GenMax API key not configured. Please set it in Admin > Tool Settings.');
        }

        return $key;
    }

    protected function request(string $method, string $endpoint, array $data = [], array $query = [])
    {
        $url = $this->baseUrl . $endpoint;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        try {
            // getApiKey() must be called INSIDE the try block: it throws
            // \RuntimeException when no key is configured, and this method is
            // called from textToSpeech()/textToSpeechSrt() AFTER credits have
            // already been pre-deducted. If the exception escaped uncaught, the
            // caller's `if (!$result['success'])` refund path would never run,
            // leaving the user's credits deducted with no refund. Catching it
            // here (\RuntimeException extends \Exception, so the existing catch
            // below already handles it) converts it into the same
            // ['success' => false, ...] shape as any other provider failure, so
            // the existing refund-on-failure logic handles it for free.
            $request = Http::withHeaders([
                'xi-api-key' => $this->getApiKey(),
            ])->timeout(30);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $data),
                'DELETE' => $request->delete($url),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json(),
                'headers' => $response->headers(),
            ];
        } catch (\Exception $e) {
            Log::error('GenMax API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 500,
                'data' => ['error' => $this->clientSafeErrorMessage($e)],
                'headers' => [],
            ];
        }
    }

    /**
     * Build an error message safe to return to API clients. getApiKey()'s
     * \RuntimeException carries an internal configuration detail ("GenMax API
     * key not configured. Please set it in Admin > Tool Settings.") that
     * shouldn't be disclosed to callers — the full detail is already captured
     * via Log::error() above, using $e->getMessage() directly. Other
     * exceptions here are transport-level failures (timeouts, DNS, etc.)
     * whose message is safe to surface as-is.
     */
    protected function clientSafeErrorMessage(\Exception $e): string
    {
        if ($e instanceof \RuntimeException) {
            return 'Dịch vụ tạm thời không khả dụng. Vui lòng thử lại sau.';
        }

        return 'Lỗi kết nối tới nhà cung cấp dịch vụ: ' . $e->getMessage();
    }

    protected function requestMultipart(string $endpoint, array $multipart)
    {
        try {
            $request = Http::withHeaders([
                'xi-api-key' => $this->getApiKey(),
            ])->timeout(60);

            foreach ($multipart as $field) {
                if (isset($field['file'])) {
                    $request = $request->attach($field['name'], $field['file'], $field['filename'] ?? null);
                }
            }

            $formData = [];
            foreach ($multipart as $field) {
                if (!isset($field['file'])) {
                    $formData[$field['name']] = $field['value'];
                }
            }

            $response = $request->post($this->baseUrl . $endpoint, $formData);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error('GenMax API multipart request failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            $message = $e instanceof \RuntimeException
                ? 'Dịch vụ tạm thời không khả dụng. Vui lòng thử lại sau.'
                : 'Lỗi kết nối: ' . $e->getMessage();

            return [
                'success' => false,
                'status' => 500,
                'data' => ['error' => $message],
            ];
        }
    }

    public function textToSpeech(User $user, string $voiceId, array $params): array
    {
        $text = $params['text'] ?? '';

        // Premium gate MUST run before the cache lookup. The cache key is
        // user-agnostic (built only from voice_id/text/params), so checking
        // cache first would let a free-tier user receive a premium user's
        // already-cached audio for an identical request, bypassing the paywall.
        if (!$user->isPremium()) {
            return [
                'success' => false,
                'status' => 403,
                'data' => ['error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.'],
            ];
        }

        $cacheKey = $this->buildTtsCacheKey($voiceId, $params);
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return ['success' => true, 'status' => 200, 'data' => $cached];
        }

        $estimate = CreditService::estimate($text);
        $estimatedCredits = $estimate['credits'];

        $monthlyBeforeDeduction = $user->monthly_credits;

        $deducted = $user->deductCredits(
            $estimatedCredits,
            "TTS pre-deduct: " . mb_substr($text, 0, 50) . (mb_strlen($text) > 50 ? '...' : ''),
            'tts_pre_deduct',
            null
        );

        if (!$deducted) {
            return [
                'success' => false,
                'status' => 402,
                'data' => [
                    'error' => 'Không đủ thời lượng',
                    'minutes_required' => CreditService::creditsToMinutes($estimatedCredits, $this->charsPerMinute),
                    'minutes_remaining' => CreditService::creditsToMinutes($user->credits, $this->charsPerMinute),
                    'credits_required' => $estimatedCredits,
                    'credits_available' => $user->credits,
                ],
            ];
        }

        $requestBody = ['text' => $text];
        if (!empty($params['model_id'])) $requestBody['model_id'] = $params['model_id'];
        if (!empty($params['provider'])) $requestBody['provider'] = $params['provider'];
        if (!empty($params['language_code'])) $requestBody['language_code'] = $params['language_code'];

        if (!empty($params['voice_settings'])) {
            $requestBody['voice_settings'] = $this->sanitizeVoiceSettings(
                $params['voice_settings'],
                $params['provider'] ?? 'elevenlabs'
            );
        }

        $result = $this->request('POST', "/v1/text-to-speech/{$voiceId}", $requestBody);

        if (!$result['success']) {
            // Refund into the same pools the pre-deduction actually drew from
            // (deductCredits() draws from monthly_credits first, then
            // purchased_credits for any remainder) so a mixed-pool user doesn't
            // have their purchased credits misclassified as monthly credits,
            // which typically expire on the next monthly reset.
            $fromMonthly = min($monthlyBeforeDeduction, $estimatedCredits);
            $fromPurchased = $estimatedCredits - $fromMonthly;

            if ($fromMonthly > 0) {
                $user->addCredits($fromMonthly, 'refund', 'TTS failed to submit — refund pre-deducted credits', 'tts_pre_deduct', null, 'monthly');
            }
            if ($fromPurchased > 0) {
                $user->addCredits($fromPurchased, 'refund', 'TTS failed to submit — refund pre-deducted credits', 'tts_pre_deduct', null, 'purchased');
            }

            return $result;
        }

        $taskId = $result['data']['id'] ?? null;

        $history = TtsHistory::create([
            'user_id' => $user->id,
            'genmax_task_id' => $taskId,
            'provider' => $params['provider'] ?? 'elevenlabs',
            'voice_id' => $voiceId,
            'model_id' => $params['model_id'] ?? null,
            'text' => $text,
            'language_code' => $params['language_code'] ?? null,
            'voice_settings' => $params['voice_settings'] ?? null,
            'status' => 'pending',
            'credits_deducted_user' => $estimatedCredits,
        ]);

        return [
            'success' => true,
            'status' => 202,
            'data' => [
                'id' => $history->id,
                'genmax_task_id' => $taskId,
                'status' => 'pending',
                'minutes_deducted' => CreditService::creditsToMinutes($estimatedCredits, $this->charsPerMinute),
                'credits_deducted' => $estimatedCredits,
            ],
        ];
    }

    public function getTaskStatus(User $user, int $historyId): array
    {
        $history = TtsHistory::where('id', $historyId)->where('user_id', $user->id)->first();

        if (!$history) {
            return ['success' => false, 'status' => 404, 'data' => ['error' => 'Không tìm thấy task']];
        }

        if (in_array($history->status, ['completed', 'failed'])) {
            return ['success' => true, 'status' => 200, 'data' => $this->formatHistoryResponse($history)];
        }

        if (!$history->genmax_task_id) {
            return ['success' => false, 'status' => 500, 'data' => ['error' => 'Không có task ID từ nhà cung cấp']];
        }

        $result = $this->request('GET', "/v1/history/{$history->genmax_task_id}");

        if (!$result['success']) {
            return $result;
        }

        $genMaxData = $result['data'];
        $newStatus = $genMaxData['status'] ?? $history->status;

        $updateData = [
            'status' => $newStatus,
            'progress' => $genMaxData['progress'] ?? $history->progress,
        ];

        if ($newStatus === 'completed') {
            $providerCharsUsed = $genMaxData['characters_used'] ?? ($genMaxData['credits_deducted'] ?? 0);
            $actualUserCredits = CreditService::charactersToCredits($providerCharsUsed);

            $updateData['characters_used'] = $genMaxData['characters_used'] ?? 0;
            $updateData['credits_deducted_provider'] = $providerCharsUsed;
            $updateData['audio_url'] = $genMaxData['result']['audio_url']
                ?? $genMaxData['audio_url']
                ?? $genMaxData['output']['audio_url']
                ?? null;

            // Atomically claim the credit reconciliation for this history row.
            // The read of $history, this guard, and the eventual $history->update()
            // below are separated by the slow provider HTTP call above (no lock,
            // no transaction), so two overlapping polls for the same task could
            // otherwise both observe is_credit_deducted === false (each from its
            // own TtsHistory::first() at the top of this method) and both mutate
            // credits. A single conditional UPDATE is atomic at the storage layer
            // (it locks/re-reads the row's current committed value, not a stale
            // snapshot) — only the request whose UPDATE actually flips the flag
            // (1 row affected) is allowed to proceed with the credit mutation.
            $reconciledCredits = DB::transaction(function () use ($history, $user, $actualUserCredits) {
                $claimed = TtsHistory::where('id', $history->id)
                    ->where('is_credit_deducted', false)
                    ->update(['is_credit_deducted' => true]);

                if ($claimed !== 1) {
                    return null;
                }

                $preDeducted = $history->credits_deducted_user;
                $diff = $preDeducted - $actualUserCredits;

                if ($diff > 0) {
                    // KNOWN LIMITATION: this refund always lands in monthly_credits.
                    // TtsHistory does not persist how the original pre-deduction was
                    // split between monthly_credits and purchased_credits (that split
                    // only exists transiently inside User::deductCredits() at submit
                    // time), and polling happens in a later, separate request — so
                    // there is nothing to recompute the split from here. For a
                    // mixed-pool user (some of the pre-deduction drawn from
                    // purchased_credits), this misclassifies those purchased credits
                    // as monthly credits, which typically expire on the next monthly
                    // reset. See GenMaxServiceTest::test_get_task_status_refund_documents_mixed_pool_limitation
                    // for the documented current behavior. A proper fix requires
                    // persisting the monthly/purchased split on TtsHistory at
                    // pre-deduction time.
                    $user->addCredits($diff, 'refund', "TTS credit adjustment (hoàn lại {$diff} credits)", 'tts_history', $history->id, 'monthly');
                    return $actualUserCredits;
                }

                if ($diff < 0) {
                    $chargeSuccess = $user->deductCredits(abs($diff), "TTS credit adjustment (trừ thêm " . abs($diff) . " credits)", 'tts_history', $history->id);

                    if ($chargeSuccess) {
                        return $actualUserCredits;
                    }

                    Log::warning('TTS underpayment charge failed — user has insufficient credits', [
                        'user_id' => $user->id,
                        'history_id' => $history->id,
                        'pre_deducted' => $preDeducted,
                        'actual_required' => $actualUserCredits,
                        'shortfall' => abs($diff),
                    ]);
                    return $preDeducted;
                }

                return $actualUserCredits;
            });

            if ($reconciledCredits !== null) {
                $updateData['credits_deducted_user'] = $reconciledCredits;
                $updateData['is_credit_deducted'] = true;

                // Only cache a "completed" response when it actually carries a
                // playable audio URL. Caching a null-audio_url response would
                // poison every identical subsequent request with the same
                // no-audio "completed" result for the full 5-hour TTL, with no
                // way to retry.
                if ($updateData['audio_url'] !== null) {
                    $ttsCacheKey = $this->buildTtsCacheKey($history->voice_id, [
                        'text' => $history->text,
                        'model_id' => $history->model_id,
                        'provider' => $history->provider,
                        'language_code' => $history->language_code,
                        'voice_settings' => $history->voice_settings,
                    ]);

                    Cache::put($ttsCacheKey, [
                        'id' => $history->id,
                        'status' => 'completed',
                        'audio_url' => $updateData['audio_url'],
                        'cached' => true,
                        'cached_at' => now()->toIso8601String(),
                        'characters_used' => $updateData['characters_used'] ?? 0,
                        'credits_deducted' => 0,
                        'minutes_deducted' => 0,
                    ], self::TTS_CACHE_TTL);
                }
            }
        }

        if ($newStatus === 'failed') {
            $updateData['error'] = $genMaxData['error'] ?? 'Unknown error';

            if ($history->credits_deducted_user > 0) {
                // Same atomic-claim protection as the completed branch above —
                // only the request that actually flips is_credit_deducted from
                // false to true (via this conditional UPDATE) is allowed to
                // refund credits.
                $claimed = DB::transaction(function () use ($history) {
                    return TtsHistory::where('id', $history->id)
                        ->where('is_credit_deducted', false)
                        ->update(['is_credit_deducted' => true]) === 1;
                });

                if ($claimed) {
                    // KNOWN LIMITATION: same caveat as the over-refund branch above —
                    // this always refunds into monthly_credits because the original
                    // monthly/purchased split at pre-deduction time isn't persisted on
                    // TtsHistory and can't be recovered at poll time. A mixed-pool user
                    // can have purchased credits misclassified as monthly credits here.
                    // See GenMaxServiceTest::test_get_task_status_refund_documents_mixed_pool_limitation.
                    $user->addCredits($history->credits_deducted_user, 'refund', "TTS failed - hoàn credits", 'tts_history', $history->id, 'monthly');
                    $updateData['credits_deducted_user'] = 0;
                    $updateData['is_credit_deducted'] = true;
                }
            }
        }

        $history->update($updateData);
        $history->refresh();

        return ['success' => true, 'status' => 200, 'data' => $this->formatHistoryResponse($history)];
    }

    protected function formatHistoryResponse(TtsHistory $history): array
    {
        return [
            'id' => $history->id,
            'genmax_task_id' => $history->genmax_task_id,
            'status' => $history->status,
            'progress' => $history->progress,
            'provider' => $history->provider,
            'voice_id' => $history->voice_id,
            'model_id' => $history->model_id,
            'text' => $history->text,
            'language_code' => $history->language_code,
            'voice_settings' => $history->voice_settings,
            'characters_used' => $history->characters_used,
            'minutes_deducted' => CreditService::creditsToMinutes($history->credits_deducted_user ?? 0, $this->charsPerMinute),
            'credits_deducted_user' => $history->credits_deducted_user,
            'audio_url' => $history->audio_url,
            'error' => $history->error,
            'created_at' => $history->created_at?->toIso8601String(),
            'updated_at' => $history->updated_at?->toIso8601String(),
        ];
    }

    protected function sanitizeVoiceSettings(array $settings, string $provider): array
    {
        $allowedKeys = self::VOICE_SETTINGS_MAP[$provider] ?? [];

        if (empty($allowedKeys)) {
            return $settings;
        }

        $filtered = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $settings)) {
                $value = $settings[$key];

                if ($key === 'use_speaker_boost') {
                    $filtered[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                } elseif ($key === 'pitch') {
                    $filtered[$key] = (int) $value;
                } else {
                    $filtered[$key] = is_numeric($value) ? (float) $value : $value;
                }
            }
        }

        return $filtered;
    }

    protected function buildTtsCacheKey(string $voiceId, array $params): string
    {
        $parts = [
            'text' => $params['text'] ?? '',
            'voice_id' => $voiceId,
            'model_id' => $params['model_id'] ?? '',
            'provider' => $params['provider'] ?? 'elevenlabs',
            'language_code' => $params['language_code'] ?? '',
            'voice_settings' => $params['voice_settings'] ?? [],
        ];

        if (is_array($parts['voice_settings'])) {
            ksort($parts['voice_settings']);
        }

        return 'tts_audio:' . md5(json_encode($parts, JSON_UNESCAPED_UNICODE));
    }

    public function textToSpeechSrt(User $user, string $voiceId, string $srtContent, array $params): array
    {
        $textOnly = $this->extractTextFromSrt($srtContent);
        $estimatedCredits = CreditService::calculateCredits($textOnly);
        $totalCharacters = mb_strlen($textOnly);

        if (!$user->isPremium()) {
            return [
                'success' => false,
                'status' => 403,
                'data' => ['error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.'],
            ];
        }

        if ($user->credits < $estimatedCredits) {
            return [
                'success' => false,
                'status' => 402,
                'data' => [
                    'error' => 'Không đủ thời lượng cho toàn bộ file SRT',
                    'credits_required' => $estimatedCredits,
                    'credits_available' => $user->credits,
                    'total_characters' => $totalCharacters,
                ],
            ];
        }

        // Snapshot the monthly balance immediately before deductCredits() draws it
        // down, so a submit-failure refund below can be split back into the same
        // monthly/purchased pools it was drawn from (deductCredits() draws from
        // monthly_credits first, then purchased_credits for any remainder).
        $monthlyBeforeDeduction = $user->monthly_credits;

        $deducted = $user->deductCredits($estimatedCredits, "TTS SRT pre-deduct: {$totalCharacters} chars", 'tts_srt', null);

        if (!$deducted) {
            return [
                'success' => false,
                'status' => 402,
                'data' => ['error' => 'Không đủ credit (race condition)', 'credits_required' => $estimatedCredits, 'credits_available' => $user->credits],
            ];
        }

        $requestBody = ['text' => $srtContent];
        if (!empty($params['model_id'])) $requestBody['model_id'] = $params['model_id'];
        if (!empty($params['provider'])) $requestBody['provider'] = $params['provider'];
        if (!empty($params['language_code'])) $requestBody['language_code'] = $params['language_code'];

        if (!empty($params['voice_settings'])) {
            $requestBody['voice_settings'] = $this->sanitizeVoiceSettings($params['voice_settings'], $params['provider'] ?? 'elevenlabs');
        }

        $result = $this->request('POST', "/v1/text-to-speech/{$voiceId}", $requestBody);

        if (!$result['success']) {
            $fromMonthly = min($monthlyBeforeDeduction, $estimatedCredits);
            $fromPurchased = $estimatedCredits - $fromMonthly;

            if ($fromMonthly > 0) {
                $user->addCredits($fromMonthly, 'refund', 'TTS SRT failed — refund pre-deducted credits: ' . ($result['data']['error'] ?? 'API error'), 'tts_srt', null, 'monthly');
            }
            if ($fromPurchased > 0) {
                $user->addCredits($fromPurchased, 'refund', 'TTS SRT failed — refund pre-deducted credits: ' . ($result['data']['error'] ?? 'API error'), 'tts_srt', null, 'purchased');
            }

            return $result;
        }

        $taskId = $result['data']['id'] ?? null;

        $history = TtsHistory::create([
            'user_id' => $user->id,
            'genmax_task_id' => $taskId,
            'provider' => $params['provider'] ?? 'elevenlabs',
            'voice_id' => $voiceId,
            'model_id' => $params['model_id'] ?? null,
            'text' => $srtContent,
            'language_code' => $params['language_code'] ?? null,
            'voice_settings' => $params['voice_settings'] ?? null,
            'status' => 'pending',
            'credits_deducted_user' => $estimatedCredits,
        ]);

        return [
            'success' => true,
            'status' => 202,
            'data' => [
                'id' => $history->id,
                'genmax_task_id' => $taskId,
                'status' => 'pending',
                'total_characters' => $totalCharacters,
                'credits_deducted' => $estimatedCredits,
            ],
        ];
    }

    protected function extractTextFromSrt(string $srt): string
    {
        $srt = str_replace(["\r\n", "\r"], "\n", $srt);
        $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt);

        $blocks = preg_split('/\n\s*\n/', trim($srt));
        $texts = [];

        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            if (count($lines) < 3) continue;

            $textLines = array_slice($lines, 2);
            $text = implode(' ', array_map('trim', $textLines));
            $text = strip_tags($text);
            $text = preg_replace('/\s+/', ' ', trim($text));

            if (!empty($text)) {
                $texts[] = $text;
            }
        }

        return implode(' ', $texts);
    }

    /**
     * @deprecated Use textToSpeechSrt() instead. This method sends N individual
     * requests which hits GenMax rate limits (40 req/min) for large SRT files.
     * Ported as-is because ToolTtsController::generateFromSrt() still calls it.
     */
    public function textToSpeechBatch(User $user, string $voiceId, array $entries, array $params): array
    {
        $totalCharacters = 0;
        $totalEstimatedCredits = 0;
        foreach ($entries as $entry) {
            $totalCharacters += mb_strlen($entry['text']);
            $totalEstimatedCredits += CreditService::calculateCredits($entry['text']);
        }

        if (!$user->isPremium()) {
            return [
                'success' => false,
                'status' => 403,
                'data' => ['error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.'],
            ];
        }

        if ($user->credits < $totalEstimatedCredits) {
            return [
                'success' => false,
                'status' => 402,
                'data' => [
                    'error' => 'Không đủ thời lượng cho toàn bộ file SRT',
                    'minutes_required' => CreditService::creditsToMinutes($totalEstimatedCredits, $this->charsPerMinute),
                    'minutes_remaining' => CreditService::creditsToMinutes($user->credits, $this->charsPerMinute),
                    'credits_required' => $totalEstimatedCredits,
                    'credits_available' => $user->credits,
                    'total_entries' => count($entries),
                    'total_characters' => $totalCharacters,
                ],
            ];
        }

        // Snapshot the monthly balance immediately before deductCredits() draws it
        // down for the WHOLE batch in one call. Individual entries get refunded
        // one at a time as they fail below; $monthlyBudgetRemaining tracks how
        // much of the original monthly-drawn portion is still unrefunded, so each
        // per-entry (or aggregate, in the catch block) refund can be split back
        // proportionally into the same monthly/purchased pools the upfront
        // deduction actually drew from, instead of landing in a single hardcoded
        // pool.
        $monthlyBeforeDeduction = $user->monthly_credits;

        $deducted = $user->deductCredits($totalEstimatedCredits, "TTS SRT batch pre-deduct: {$totalCharacters} chars, " . count($entries) . " entries", 'tts_batch', null);

        if (!$deducted) {
            return [
                'success' => false,
                'status' => 402,
                'data' => ['error' => 'Không đủ credit (race condition)', 'credits_required' => $totalEstimatedCredits, 'credits_available' => $user->credits],
            ];
        }

        $monthlyBudgetRemaining = min($monthlyBeforeDeduction, $totalEstimatedCredits);

        $processedEntryIndices = [];
        $tasks = [];
        $creditsRefunded = 0;

        try {
            foreach ($entries as $idx => $entry) {
                $text = $entry['text'];
                $entryCredits = CreditService::calculateCredits($text);

                $requestBody = ['text' => $text];
                if (!empty($params['model_id'])) $requestBody['model_id'] = $params['model_id'];
                if (!empty($params['provider'])) $requestBody['provider'] = $params['provider'];
                if (!empty($params['language_code'])) $requestBody['language_code'] = $params['language_code'];

                if (!empty($params['voice_settings'])) {
                    $requestBody['voice_settings'] = $this->sanitizeVoiceSettings($params['voice_settings'], $params['provider'] ?? 'elevenlabs');
                }

                $result = $this->request('POST', "/v1/text-to-speech/{$voiceId}", $requestBody);

                $processedEntryIndices[] = $idx;

                if (!$result['success']) {
                    $fromMonthly = min($monthlyBudgetRemaining, $entryCredits);
                    $fromPurchased = $entryCredits - $fromMonthly;

                    if ($fromMonthly > 0) {
                        $user->addCredits($fromMonthly, 'refund', "TTS SRT entry #{$entry['index']} failed: " . ($result['data']['error'] ?? 'API error'), 'tts_batch', null, 'monthly');
                    }
                    if ($fromPurchased > 0) {
                        $user->addCredits($fromPurchased, 'refund', "TTS SRT entry #{$entry['index']} failed: " . ($result['data']['error'] ?? 'API error'), 'tts_batch', null, 'purchased');
                    }
                    $monthlyBudgetRemaining -= $fromMonthly;
                    $creditsRefunded += $entryCredits;

                    $tasks[] = [
                        'srt_index' => $entry['index'],
                        'srt_start' => $entry['start'],
                        'srt_end' => $entry['end'],
                        'status' => 'failed',
                        'error' => $result['data']['error'] ?? 'Lỗi gửi tới nhà cung cấp',
                        'credits_refunded' => $entryCredits,
                    ];
                    continue;
                }

                $taskId = $result['data']['id'] ?? null;

                $history = TtsHistory::create([
                    'user_id' => $user->id,
                    'genmax_task_id' => $taskId,
                    'provider' => $params['provider'] ?? 'elevenlabs',
                    'voice_id' => $voiceId,
                    'model_id' => $params['model_id'] ?? null,
                    'text' => $text,
                    'language_code' => $params['language_code'] ?? null,
                    'voice_settings' => $params['voice_settings'] ?? null,
                    'status' => 'pending',
                    'credits_deducted_user' => $entryCredits,
                ]);

                $tasks[] = [
                    'id' => $history->id,
                    'genmax_task_id' => $taskId,
                    'srt_index' => $entry['index'],
                    'srt_start' => $entry['start'],
                    'srt_end' => $entry['end'],
                    'status' => 'pending',
                    'credits_deducted' => $entryCredits,
                ];
            }
        } catch (\Throwable $e) {
            $remainingCredits = 0;
            foreach ($entries as $idx => $entry) {
                if (!in_array($idx, $processedEntryIndices)) {
                    $remainingCredits += CreditService::calculateCredits($entry['text']);
                }
            }

            if ($remainingCredits > 0) {
                // Split against whatever monthly budget hasn't already been consumed
                // by per-entry refunds earlier in this same call (see
                // $monthlyBudgetRemaining above) — same proportional pattern as the
                // per-entry refund, just applied to one aggregate amount.
                $fromMonthly = min($monthlyBudgetRemaining, $remainingCredits);
                $fromPurchased = $remainingCredits - $fromMonthly;

                if ($fromMonthly > 0) {
                    $user->addCredits($fromMonthly, 'refund', 'TTS SRT batch interrupted — refund unprocessed entries', 'tts_batch', null, 'monthly');
                }
                if ($fromPurchased > 0) {
                    $user->addCredits($fromPurchased, 'refund', 'TTS SRT batch interrupted — refund unprocessed entries', 'tts_batch', null, 'purchased');
                }
                $monthlyBudgetRemaining -= $fromMonthly;
                $creditsRefunded += $remainingCredits;
            }

            Log::error('TTS SRT batch interrupted', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'processed' => count($processedEntryIndices),
                'total' => count($entries),
                'credits_refunded' => $remainingCredits,
            ]);
        }

        $actualDeducted = $totalEstimatedCredits - $creditsRefunded;

        return [
            'success' => true,
            'status' => 202,
            'data' => [
                'tasks' => $tasks,
                'total_entries' => count($entries),
                'minutes_deducted' => CreditService::creditsToMinutes($actualDeducted, $this->charsPerMinute),
                'total_credits_deducted' => $actualDeducted,
                'credits_refunded' => $creditsRefunded,
            ],
        ];
    }

    public function getUserHistory(User $user, int $pageSize = 30, int $page = 1): array
    {
        $histories = TtsHistory::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHours(48))
            ->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'tasks' => $histories->map(fn($h) => $this->formatHistoryResponse($h))->toArray(),
                'has_more' => $histories->hasMorePages(),
                'total' => $histories->total(),
                'current_page' => $histories->currentPage(),
                'last_page' => $histories->lastPage(),
            ],
        ];
    }

    public function deleteHistory(User $user, int $historyId): array
    {
        $history = TtsHistory::where('id', $historyId)->where('user_id', $user->id)->first();

        if (!$history) {
            return ['success' => false, 'status' => 404, 'data' => ['error' => 'Không tìm thấy']];
        }

        if ($history->genmax_task_id) {
            $this->request('DELETE', "/v1/history/{$history->genmax_task_id}");
        }

        $history->delete();

        return ['success' => true, 'status' => 200, 'data' => ['message' => 'Đã xóa']];
    }

    public function getModels(?string $provider = null): array
    {
        $query = $provider ? ['provider' => $provider] : [];
        return $this->request('GET', '/v1/models', [], $query);
    }

    public function getSystemVoices(array $filters = []): array
    {
        return $this->request('GET', '/v1/minimax/system-voices', [], $filters);
    }

    public function getSystemVoicesClone(array $filters = []): array
    {
        return $this->request('GET', '/v1/minimax/voices/', [], $filters);
    }

    public function getClonedVoices(): array
    {
        return $this->request('GET', '/v1/minimax/voices');
    }

    public function cloneVoice(array $multipart): array
    {
        return $this->requestMultipart('/v1/minimax/voices/clone', $multipart);
    }

    public function deleteVoice(string $voiceId): array
    {
        return $this->request('DELETE', "/v1/minimax/voices/{$voiceId}");
    }
}
