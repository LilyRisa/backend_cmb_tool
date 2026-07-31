<?php

namespace Tests\Unit;

use App\Services\GroqService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.api_key' => 'test-groq-key']);
    }

    public function test_transcribe_converts_segments_to_srt(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'segments' => [
                    ['start' => 0.0, 'end' => 2.5, 'text' => ' Hello there '],
                    ['start' => 2.5, 'end' => 4.0, 'text' => ' Second line '],
                ],
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $srt = (new GroqService())->transcribe($file);

        $this->assertStringContainsString("1\n00:00:00,000 --> 00:00:02,500\nHello there", $srt);
        $this->assertStringContainsString("2\n00:00:02,500 --> 00:00:04,000\nSecond line", $srt);
    }

    public function test_transcribe_falls_back_to_plain_text_when_no_segments(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['text' => 'Just some text'], 200),
        ]);

        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $srt = (new GroqService())->transcribe($file);

        $this->assertStringContainsString('Just some text', $srt);
        $this->assertStringStartsWith("1\n00:00:00,000 --> 00:00:01,000\n", $srt);
    }

    public function test_transcribe_throws_on_api_error(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'invalid file']], 400),
        ]);

        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid file');

        (new GroqService())->transcribe($file);
    }

    public function test_transcribe_passes_language_hint_when_provided(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['segments' => []], 200)]);
        $file = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

        (new GroqService())->transcribe($file, 'vi');

        Http::assertSent(function ($request) {
            return str_contains($request->body(), 'vi');
        });
    }

    public function test_transcribe_raw_wraps_a_file_path_into_an_uploaded_file(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['segments' => [['start' => 0, 'end' => 1, 'text' => 'Hi']]], 200)]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'groqtest');
        file_put_contents($tmpPath, 'fake audio bytes');

        $srt = (new GroqService())->transcribeRaw($tmpPath, 'original.mp3');

        $this->assertStringContainsString('Hi', $srt);
        @unlink($tmpPath);
    }

    public function test_format_timestamp_carries_milliseconds_that_round_to_1000(): void
    {
        $service = new GroqService();
        $method = new \ReflectionMethod($service, 'formatTimestamp');
        $method->setAccessible(true);

        $result = $method->invoke($service, 3.99999);

        $this->assertSame('00:00:04,000', $result);
    }
}
