<?php

namespace Tests\Unit;

use App\Models\SystemSetting;
use App\Models\TtsHistory;
use App\Models\User;
use App\Services\GenMaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenMaxServiceTest extends TestCase
{
    use RefreshDatabase;

    private GenMaxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::setGenMaxApiKey('sk-test-key');
        $this->service = new GenMaxService();
    }

    private function premiumUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'package_type' => 'premium',
            'package_expires_at' => now()->addDays(10),
            'monthly_credits' => 1000,
            'purchased_credits' => 0,
            'credits' => 1000,
        ], $overrides));
    }

    public function test_text_to_speech_rejects_non_premium_user(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello']);

        $this->assertFalse($result['success']);
        $this->assertEquals(403, $result['status']);
    }

    public function test_text_to_speech_rejects_insufficient_credits(): void
    {
        $user = $this->premiumUser(['monthly_credits' => 1, 'purchased_credits' => 0, 'credits' => 1]);

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => str_repeat('a', 500)]);

        $this->assertEquals(402, $result['status']);
        $this->assertEquals(1, $user->fresh()->credits);
    }

    public function test_text_to_speech_pre_deducts_credits_and_creates_history_on_success(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::response(['id' => 'genmax_task_123'], 200),
        ]);
        $user = $this->premiumUser();

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);

        $this->assertTrue($result['success']);
        $this->assertEquals(202, $result['status']);
        $this->assertEquals('pending', $result['data']['status']);
        $this->assertDatabaseHas('tts_histories', [
            'user_id' => $user->id,
            'genmax_task_id' => 'genmax_task_123',
            'voice_id' => 'voice_abc',
            'status' => 'pending',
        ]);
        $this->assertLessThan(1000, $user->fresh()->monthly_credits);
    }

    public function test_text_to_speech_refunds_credits_when_genmax_fails(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::response(['error' => 'provider down'], 500),
        ]);
        $user = $this->premiumUser();

        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);

        $this->assertFalse($result['success']);
        $this->assertEquals(1000, $user->fresh()->monthly_credits);
    }

    public function test_text_to_speech_uses_cache_for_identical_repeat_request(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::sequence()
                ->push(['id' => 'genmax_task_1'], 200)
                ->push(['status' => 'completed', 'characters_used' => 11, 'result' => ['audio_url' => 'https://cdn/audio1.mp3']], 200),
        ]);
        $user = $this->premiumUser();

        $first = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);
        $this->service->getTaskStatus($user, $first['data']['id']);

        $creditsBeforeSecond = $user->fresh()->monthly_credits;
        $second = $this->service->textToSpeech($user, 'voice_abc', ['text' => 'Hello world']);

        $this->assertTrue($second['success']);
        $this->assertEquals(200, $second['status']);
        $this->assertTrue($second['data']['cached'] ?? false);
        $this->assertEquals($creditsBeforeSecond, $user->fresh()->monthly_credits);
    }

    public function test_text_to_speech_rejects_free_user_even_when_identical_request_is_cached(): void
    {
        // The TTS cache key is user-agnostic (voice_id/text/params only), so a
        // free-tier user must NOT be able to piggyback on a premium user's
        // already-cached result to get the paywalled feature for free.
        Http::fake([
            'api.genmax.io/*' => Http::sequence()
                ->push(['id' => 'genmax_task_cache_bypass'], 200)
                ->push(['status' => 'completed', 'characters_used' => 11, 'result' => ['audio_url' => 'https://cdn/audio1.mp3']], 200),
        ]);
        $premiumUser = $this->premiumUser();

        $first = $this->service->textToSpeech($premiumUser, 'voice_abc', ['text' => 'Hello world']);
        $this->service->getTaskStatus($premiumUser, $first['data']['id']);

        // Sanity check: the request is genuinely cached now (same voice/text/params).
        $cachedRepeat = $this->service->textToSpeech($premiumUser, 'voice_abc', ['text' => 'Hello world']);
        $this->assertTrue($cachedRepeat['data']['cached'] ?? false);

        $freeUser = User::factory()->create(['package_type' => 'free']);

        $result = $this->service->textToSpeech($freeUser, 'voice_abc', ['text' => 'Hello world']);

        $this->assertFalse($result['success']);
        $this->assertEquals(403, $result['status']);
        $this->assertArrayNotHasKey('cached', $result['data']);
    }

    public function test_get_task_status_returns_404_for_missing_history(): void
    {
        $user = $this->premiumUser();

        $result = $this->service->getTaskStatus($user, 999999);

        $this->assertEquals(404, $result['status']);
    }

    public function test_get_task_status_polls_genmax_and_marks_completed_with_refund(): void
    {
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'genmax_task_id' => 'genmax_task_1',
            'text' => str_repeat('a', 100),
            'credits_deducted_user' => 10,
            'status' => 'pending',
        ]);
        $user->decrement('monthly_credits', 10);

        Http::fake([
            'api.genmax.io/*' => Http::response([
                'status' => 'completed',
                'progress' => 100,
                'characters_used' => 50,
                'result' => ['audio_url' => 'https://cdn.genmax.io/audio/1.mp3'],
            ], 200),
        ]);

        $result = $this->service->getTaskStatus($user, $history->id);

        $this->assertEquals(200, $result['status']);
        $this->assertEquals('completed', $result['data']['status']);
        $this->assertEquals('https://cdn.genmax.io/audio/1.mp3', $result['data']['audio_url']);
        $this->assertTrue($history->fresh()->is_credit_deducted);
        $this->assertGreaterThan(990, $user->fresh()->monthly_credits);
    }

    public function test_get_task_status_refunds_all_credits_on_failure(): void
    {
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create([
            'user_id' => $user->id,
            'genmax_task_id' => 'genmax_task_2',
            'credits_deducted_user' => 15,
            'status' => 'pending',
        ]);
        $user->decrement('monthly_credits', 15);

        Http::fake([
            'api.genmax.io/*' => Http::response(['status' => 'failed', 'error' => 'synthesis error'], 200),
        ]);

        $this->service->getTaskStatus($user, $history->id);

        $this->assertEquals(1000, $user->fresh()->monthly_credits);
        $this->assertEquals('failed', $history->fresh()->status);
        $this->assertEquals(0, $history->fresh()->credits_deducted_user);
    }

    public function test_text_to_speech_refund_splits_across_pools_for_mixed_pool_user(): void
    {
        // When a submit-time refund happens (provider request itself failed),
        // GenMaxService knows the exact monthly/purchased split it just drew
        // from (it snapshotted monthly_credits right before deductCredits()),
        // so the refund should be split back proportionally rather than
        // dumping everything into monthly_credits.
        Http::fake([
            'api.genmax.io/*' => Http::response(['error' => 'provider down'], 500),
        ]);
        $user = $this->premiumUser(['monthly_credits' => 3, 'purchased_credits' => 10, 'credits' => 13]);

        // 41 chars => ceil(41 / 10) = 5 credits required.
        $result = $this->service->textToSpeech($user, 'voice_abc', ['text' => str_repeat('a', 41)]);

        $this->assertFalse($result['success']);
        $user->refresh();
        $this->assertEquals(3, $user->monthly_credits);
        $this->assertEquals(10, $user->purchased_credits);
        $this->assertEquals(13, $user->credits);
    }

    public function test_get_task_status_refund_documents_mixed_pool_limitation(): void
    {
        // KNOWN LIMITATION (see comments in GenMaxService::getTaskStatus()):
        // TtsHistory does not persist the monthly/purchased split that was
        // drawn at pre-deduction time, and polling for status happens in a
        // later, separate call — so a refund issued from getTaskStatus() has
        // no way to recover that split and always lands fully in
        // monthly_credits. This test pins down today's actual (imperfect)
        // behavior for a mixed-pool user so that a future proper fix (e.g.
        // persisting the split on TtsHistory) shows up as a deliberate,
        // visible diff rather than a silent behavior change.
        // Both requests must be registered in a single Http::fake() call with a
        // sequence: a second Http::fake() call for the same URL pattern does
        // NOT override the first (Laravel matches the earliest-registered
        // stub), so registering the "failed" status response separately later
        // would silently keep serving the submit response to the GET call.
        Http::fake([
            'api.genmax.io/*' => Http::sequence()
                ->push(['id' => 'genmax_task_mixed'], 200)
                ->push(['status' => 'failed', 'error' => 'synthesis error'], 200),
        ]);
        $user = $this->premiumUser(['monthly_credits' => 3, 'purchased_credits' => 10, 'credits' => 13]);

        // 41 chars => ceil(41 / 10) = 5 credits: 3 from monthly (all of it), 2 from purchased.
        $submit = $this->service->textToSpeech($user, 'voice_abc', ['text' => str_repeat('a', 41)]);
        $this->assertTrue($submit['success']);

        $user->refresh();
        $this->assertEquals(0, $user->monthly_credits);
        $this->assertEquals(8, $user->purchased_credits);

        $this->service->getTaskStatus($user, $submit['data']['id']);

        $user->refresh();
        // Documents current behavior: the full 5-credit refund lands entirely
        // in monthly_credits (0 -> 5) instead of being split back 3/2 to
        // monthly/purchased as it was originally drawn. purchased_credits
        // stays at 8 — the 2 purchased credits that should have been restored
        // there are effectively reclassified as monthly credits instead.
        $this->assertEquals(5, $user->monthly_credits);
        $this->assertEquals(8, $user->purchased_credits);
    }

    public function test_text_to_speech_srt_pre_deducts_based_on_text_only_characters(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['id' => 'genmax_srt_1'], 200)]);
        $user = $this->premiumUser();
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nHello.\n";

        $result = $this->service->textToSpeechSrt($user, 'voice_abc', $srt, []);

        $this->assertTrue($result['success']);
        $this->assertEquals(202, $result['status']);
        $this->assertEquals(6, $result['data']['total_characters']); // "Hello." = 6 chars
    }

    public function test_text_to_speech_srt_rejects_non_premium(): void
    {
        $user = User::factory()->create(['package_type' => 'free']);

        $result = $this->service->textToSpeechSrt($user, 'voice_abc', "1\n00:00:01,000 --> 00:00:02,000\nHi.\n", []);

        $this->assertEquals(403, $result['status']);
    }

    public function test_text_to_speech_batch_creates_one_history_per_entry(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['id' => 'genmax_batch_task'], 200)]);
        $user = $this->premiumUser();
        $entries = [
            ['index' => 1, 'start' => '00:00:01,000', 'end' => '00:00:02,000', 'text' => 'First'],
            ['index' => 2, 'start' => '00:00:03,000', 'end' => '00:00:04,000', 'text' => 'Second'],
        ];

        $result = $this->service->textToSpeechBatch($user, 'voice_abc', $entries, []);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['tasks']);
        $this->assertDatabaseCount('tts_histories', 2);
    }

    public function test_text_to_speech_batch_refunds_only_failed_entries(): void
    {
        Http::fake([
            'api.genmax.io/*' => Http::sequence()
                ->push(['id' => 'ok_task'], 200)
                ->push(['error' => 'provider rejected'], 400),
        ]);
        $user = $this->premiumUser();
        $entries = [
            ['index' => 1, 'start' => '00:00:01,000', 'end' => '00:00:02,000', 'text' => 'Good entry'],
            ['index' => 2, 'start' => '00:00:03,000', 'end' => '00:00:04,000', 'text' => 'Bad entry'],
        ];

        $result = $this->service->textToSpeechBatch($user, 'voice_abc', $entries, []);

        $this->assertEquals('failed', $result['data']['tasks'][1]['status']);
        $this->assertGreaterThan(0, $result['data']['credits_refunded']);
        $this->assertDatabaseCount('tts_histories', 1);
    }

    public function test_text_to_speech_srt_refund_splits_across_pools_for_mixed_pool_user(): void
    {
        // Same rationale as Task 3's test_text_to_speech_refund_splits_across_pools_for_mixed_pool_user:
        // this refund happens synchronously in the same call as the pre-deduction
        // (unlike getTaskStatus()'s later-request refunds), so there's no schema
        // gap blocking a proportional monthly/purchased split.
        Http::fake(['api.genmax.io/*' => Http::response(['error' => 'provider down'], 500)]);
        $user = $this->premiumUser(['monthly_credits' => 3, 'purchased_credits' => 10, 'credits' => 13]);
        // 41 chars => ceil(41/10) = 5 credits: 3 from monthly (all of it), 2 from purchased.
        $srt = "1\n00:00:01,000 --> 00:00:02,000\n" . str_repeat('a', 41) . "\n";

        $result = $this->service->textToSpeechSrt($user, 'voice_abc', $srt, []);

        $this->assertFalse($result['success']);
        $user->refresh();
        $this->assertEquals(3, $user->monthly_credits);
        $this->assertEquals(10, $user->purchased_credits);
    }

    public function test_text_to_speech_batch_per_entry_refund_splits_across_pools_for_mixed_pool_user(): void
    {
        // The single upfront deductCredits() call for the whole batch draws
        // monthly=3 (all of it) + purchased=2 for a 5-credit total. Entry 1
        // (1 credit) succeeds and is never refunded. Entry 2 (4 credits) fails;
        // its refund must draw from the remaining monthly budget (3) first, then
        // spill 1 credit into purchased — a single refund spanning both pools —
        // rather than dumping all 4 credits into one hardcoded pool.
        Http::fake([
            'api.genmax.io/*' => Http::sequence()
                ->push(['id' => 'ok_task'], 200)
                ->push(['error' => 'provider rejected'], 400),
        ]);
        $user = $this->premiumUser(['monthly_credits' => 3, 'purchased_credits' => 10, 'credits' => 13]);
        $entries = [
            ['index' => 1, 'start' => '00:00:01,000', 'end' => '00:00:02,000', 'text' => 'A'], // 1 credit
            ['index' => 2, 'start' => '00:00:03,000', 'end' => '00:00:04,000', 'text' => str_repeat('B', 35)], // 4 credits
        ];

        $result = $this->service->textToSpeechBatch($user, 'voice_abc', $entries, []);

        $this->assertEquals('failed', $result['data']['tasks'][1]['status']);
        $user->refresh();
        // monthly: 0 (post pre-deduct) + 3 (refund) = 3; purchased: 8 (post pre-deduct) + 1 (refund) = 9.
        $this->assertEquals(3, $user->monthly_credits);
        $this->assertEquals(9, $user->purchased_credits);
    }

    public function test_text_to_speech_batch_interrupted_refund_splits_across_pools_for_mixed_pool_user(): void
    {
        // Proves the catch-block's aggregate "interrupted batch" refund also
        // splits proportionally, using whatever monthly budget remains after any
        // per-entry refunds earlier in the same call. Simulates an interruption by
        // overriding request() (instead of relying on Http::fake(), since
        // GenMaxService::request() already swallows HTTP-layer \Exceptions into a
        // normal failure result — it never lets them propagate to the batch loop's
        // try/catch) to throw a raw exception on the second call, after the first
        // entry has already succeeded.
        $user = $this->premiumUser(['monthly_credits' => 3, 'purchased_credits' => 10, 'credits' => 13]);
        SystemSetting::setGenMaxApiKey('sk-test-key');

        $service = new class extends GenMaxService {
            public int $calls = 0;

            protected function request(string $method, string $endpoint, array $data = [], array $query = [])
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return ['success' => true, 'status' => 200, 'data' => ['id' => 'ok_task'], 'headers' => []];
                }

                throw new \RuntimeException('simulated provider crash');
            }
        };

        $entries = [
            ['index' => 1, 'start' => '00:00:01,000', 'end' => '00:00:02,000', 'text' => 'A'], // 1 credit
            ['index' => 2, 'start' => '00:00:03,000', 'end' => '00:00:04,000', 'text' => str_repeat('B', 35)], // 4 credits
        ];

        $result = $service->textToSpeechBatch($user, 'voice_abc', $entries, []);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['tasks']);
        $user->refresh();
        // Same arithmetic as the per-entry test above: entry 2's 4 credits are
        // refunded as a single aggregate in the catch block, split 3 (remaining
        // monthly budget) + 1 (purchased).
        $this->assertEquals(3, $user->monthly_credits);
        $this->assertEquals(9, $user->purchased_credits);
    }

    public function test_get_user_history_returns_recent_paginated_records(): void
    {
        $user = $this->premiumUser();
        TtsHistory::factory()->count(3)->create(['user_id' => $user->id]);

        $result = $this->service->getUserHistory($user, 30, 1);

        $this->assertEquals(200, $result['status']);
        $this->assertCount(3, $result['data']['tasks']);
    }

    public function test_delete_history_removes_record_and_calls_genmax(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response([], 200)]);
        $user = $this->premiumUser();
        $history = TtsHistory::factory()->create(['user_id' => $user->id, 'genmax_task_id' => 'task_to_delete']);

        $result = $this->service->deleteHistory($user, $history->id);

        $this->assertEquals(200, $result['status']);
        $this->assertDatabaseMissing('tts_histories', ['id' => $history->id]);
    }

    public function test_delete_history_404s_for_missing_or_other_users_record(): void
    {
        $user = $this->premiumUser();

        $result = $this->service->deleteHistory($user, 999999);

        $this->assertEquals(404, $result['status']);
    }

    public function test_get_models_passes_through_provider_query(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['models' => ['a', 'b']], 200)]);

        $result = $this->service->getModels('elevenlabs');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'provider=elevenlabs'));
    }

    public function test_clone_voice_sends_multipart_request(): void
    {
        Http::fake(['api.genmax.io/*' => Http::response(['voice_id' => 'new_voice'], 200)]);

        $result = $this->service->cloneVoice([
            ['name' => 'voice_name', 'value' => 'My Voice'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('new_voice', $result['data']['voice_id']);
    }
}
