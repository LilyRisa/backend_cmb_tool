<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://openrouter.ai/api/v1';
    protected string $model = 'google/gemini-2.0-flash-001';

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key');
    }

    public function translate(string $text, string $targetLanguage, string $format = 'text', string $context = ''): string
    {
        $prompt = $this->buildPrompt($text, $targetLanguage, $format, $context);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.3,
            ]);

            if ($response->failed()) {
                $error = $response->json('error.message', $response->body());
                Log::error('OpenRouter API error', ['status' => $response->status(), 'error' => $error]);
                throw new \RuntimeException("OpenRouter API error: {$error}");
            }

            $result = $response->json('choices.0.message.content');

            if (empty($result)) {
                throw new \RuntimeException('OpenRouter API returned empty response.');
            }

            $result = trim($result);
            $result = preg_replace('/^```(?:\w+)?\n(.*)\n```$/s', '$1', $result);

            return trim($result);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('OpenRouter connection error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Cannot connect to OpenRouter API. Please try again later.');
        }
    }

    protected function buildPrompt(string $text, string $targetLanguage, string $format, string $context = ''): string
    {
        if ($format === 'srt') {
            $prompt = <<<PROMPT
You are a professional subtitle translator specialized in AI video dubbing.
Translate the following SRT subtitle file into {$targetLanguage}.
The translation will be used for AI voice dubbing, so it must sound natural and fit the subtitle timing.
------------------------------------------------
STRICT SRT FORMAT RULES
You MUST keep the SRT structure exactly the same.
DO NOT change:
- Subtitle numbers
- Timestamps
- Subtitle order
- Blank lines between blocks
ONLY translate the subtitle text.
If a subtitle contains multiple lines, keep the same number of lines.
Do NOT merge or split subtitle blocks.
Return ONLY the translated SRT.
------------------------------------------------
DUBBING TIMING RULES
The translated text should fit the same speaking duration.
Estimate natural speech speed:
≈ 12–15 characters per second.
Approximate maximum characters:
duration × 14
If your translation is slightly longer:
prefer shorter wording or simpler phrasing.
However:
NEVER remove important meaning just to shorten text.
Meaning accuracy is more important than strict length limits.
------------------------------------------------
TRANSLATION STYLE
Use natural spoken language suitable for voice dubbing.
Prefer:
- conversational tone
- shorter sentences
- natural phrasing
Avoid:
- overly literal translation
- overly formal written language
- unnecessary repetition
------------------------------------------------
HONORIFICS & SPEECH REGISTER
Maintain consistent speech style and relationships.
Vietnamese:
- Use correct pronouns (tôi/bạn, anh/em, cô/cháu, etc.)
- Keep natural spoken tone
Japanese:
- Preserve appropriate keigo levels
Korean:
- Maintain correct speech level (해요체 / 합쇼체 / 해체)
Chinese:
- Use correct 你 / 您 forms
Thai:
- Preserve polite particles (ครับ / ค่ะ)
------------------------------------------------
SENSITIVE CONTENT HANDLING
If the original subtitle contains sensitive, explicit, or potentially
offensive words/phrases (e.g. references to child abuse, suicide,
drugs, extreme violence, slurs, hate speech), you MUST:
- Replace them with softer, commonly understood euphemisms or
  equivalent expressions in the target language.
- The replacement must preserve the ORIGINAL MEANING so the
  listener can still understand the context.
- Do NOT censor with symbols (***) or remove the content entirely.
Examples (Vietnamese):
  "tự tử" → "tự kết liễu" or "quyên sinh"
  "ấu dâm" → "xâm hại trẻ em"
  "ma túy" → "chất cấm" or "chất gây nghiện"
  "hiếp dâm" → "xâm hại tình dục"
Apply equivalent natural euphemisms for ALL target languages.
------------------------------------------------
OUTPUT FORMAT EXAMPLE
1
00:00:01,000 --> 00:00:02,500
Translated subtitle text

2
00:00:02,600 --> 00:00:04,000
Next subtitle line
------------------------------------------------
Now translate the following SRT:
{$text}
PROMPT;

            if (!empty($context)) {
                $contextSection = <<<CONTEXT
------------------------------------------------
CONTINUITY CONTEXT (reference only — do NOT include these in your output)
The following subtitles were already translated in the previous batch.
Use them to maintain consistent:
- pronouns and honorifics (e.g. anh/em, tôi/bạn)
- terminology and proper nouns
- tone and speech style

{$context}
------------------------------------------------
CONTEXT;
                $prompt = $contextSection . "\n" . $prompt;
            }

            return $prompt;
        }

        return <<<PROMPT
Translate the following text into {$targetLanguage}.
Return ONLY the translated text.
Do NOT add explanations.
{$text}
PROMPT;
    }
}
