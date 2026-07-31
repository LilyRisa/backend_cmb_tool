<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.groq.com/openai/v1';

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key');
    }

    public function transcribeRaw(string $filePath, string $fileName, ?string $language = null): string
    {
        $fakeFile = new \Illuminate\Http\UploadedFile($filePath, $fileName, null, null, true);

        return $this->transcribe($fakeFile, $language);
    }

    public function transcribe(UploadedFile $file, ?string $language = null): string
    {
        try {
            $postData = [
                'model' => 'whisper-large-v3-turbo',
                'response_format' => 'verbose_json',
            ];

            if ($language) {
                $postData['language'] = $language;
            }

            // Attach the file as an open resource rather than its raw contents:
            // Illuminate's PendingRequest::attach() runs the multipart entry through
            // array_filter(), which silently drops the 'contents' key for falsy
            // values (e.g. an empty string read from a zero-byte file), breaking
            // the Guzzle multipart stream. A resource handle is always truthy.
            // The handle is left for Guzzle's PSR-7 stream wrapper to close on
            // destruction rather than closed here, since the HTTP client (and
            // Http::fake()'s request recorder) may still need to read the body
            // after this method returns.
            $response = Http::withToken($this->apiKey)
                ->timeout(120)
                ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
                ->post("{$this->baseUrl}/audio/transcriptions", $postData);

            if ($response->failed()) {
                $error = $response->json('error.message', $response->body());
                Log::error('Groq Whisper API error', ['status' => $response->status(), 'error' => $error]);
                throw new \RuntimeException("Groq API error: {$error}");
            }

            $data = $response->json();

            if (!isset($data['segments']) || empty($data['segments'])) {
                return "1\n00:00:00,000 --> 00:00:01,000\n" . ($data['text'] ?? '');
            }

            return $this->jsonToSrt($data['segments']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Groq Whisper connection error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Cannot connect to Groq API. Please try again later.');
        }
    }

    protected function jsonToSrt(array $segments): string
    {
        $srt = '';
        foreach ($segments as $index => $segment) {
            $seq = $index + 1;
            $startTime = $this->formatTimestamp($segment['start']);
            $endTime = $this->formatTimestamp($segment['end']);
            $text = trim($segment['text']);

            $srt .= "{$seq}\n{$startTime} --> {$endTime}\n{$text}\n\n";
        }

        return trim($srt);
    }

    protected function formatTimestamp(float $seconds): string
    {
        $totalMs = (int) round(max(0, $seconds) * 1000);

        $hours = intdiv($totalMs, 3600000);
        $totalMs -= $hours * 3600000;

        $minutes = intdiv($totalMs, 60000);
        $totalMs -= $minutes * 60000;

        $secs = intdiv($totalMs, 1000);
        $milliSeconds = $totalMs - $secs * 1000;

        return sprintf("%02d:%02d:%02d,%03d", $hours, $minutes, $secs, $milliSeconds);
    }
}
