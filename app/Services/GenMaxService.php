<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
        $request = Http::withHeaders([
            'xi-api-key' => $this->getApiKey(),
        ])->timeout(30);

        $url = $this->baseUrl . $endpoint;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        try {
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
                'data' => ['error' => 'Lỗi kết nối tới nhà cung cấp dịch vụ: ' . $e->getMessage()],
                'headers' => [],
            ];
        }
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

            return [
                'success' => false,
                'status' => 500,
                'data' => ['error' => 'Lỗi kết nối: ' . $e->getMessage()],
            ];
        }
    }

    public function textToSpeech(User $user, string $voiceId, array $params): array
    {
        $text = $params['text'] ?? '';

        $cacheKey = $this->buildTtsCacheKey($voiceId, $params);
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return ['success' => true, 'status' => 200, 'data' => $cached];
        }

        $estimate = CreditService::estimate($text);
        $estimatedCredits = $estimate['credits'];

        if (!$user->isPremium()) {
            return [
                'success' => false,
                'status' => 403,
                'data' => ['error' => 'Tính năng này yêu cầu gói Premium. Vui lòng nâng cấp tài khoản.'],
            ];
        }

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
            $user->addCredits($estimatedCredits, 'refund', 'TTS failed to submit — refund pre-deducted credits', 'tts_pre_deduct', null, 'monthly');
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

            if (!$history->is_credit_deducted) {
                $preDeducted = $history->credits_deducted_user;
                $diff = $preDeducted - $actualUserCredits;

                if ($diff > 0) {
                    $user->addCredits($diff, 'refund', "TTS credit adjustment (hoàn lại {$diff} credits)", 'tts_history', $history->id, 'monthly');
                    $updateData['credits_deducted_user'] = $actualUserCredits;
                } elseif ($diff < 0) {
                    $chargeSuccess = $user->deductCredits(abs($diff), "TTS credit adjustment (trừ thêm " . abs($diff) . " credits)", 'tts_history', $history->id);

                    if ($chargeSuccess) {
                        $updateData['credits_deducted_user'] = $actualUserCredits;
                    } else {
                        Log::warning('TTS underpayment charge failed — user has insufficient credits', [
                            'user_id' => $user->id,
                            'history_id' => $history->id,
                            'pre_deducted' => $preDeducted,
                            'actual_required' => $actualUserCredits,
                            'shortfall' => abs($diff),
                        ]);
                        $updateData['credits_deducted_user'] = $preDeducted;
                    }
                } else {
                    $updateData['credits_deducted_user'] = $actualUserCredits;
                }

                $updateData['is_credit_deducted'] = true;

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

        if ($newStatus === 'failed') {
            $updateData['error'] = $genMaxData['error'] ?? 'Unknown error';

            if (!$history->is_credit_deducted && $history->credits_deducted_user > 0) {
                $user->addCredits($history->credits_deducted_user, 'refund', "TTS failed - hoàn credits", 'tts_history', $history->id, 'monthly');
                $updateData['credits_deducted_user'] = 0;
                $updateData['is_credit_deducted'] = true;
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
}
