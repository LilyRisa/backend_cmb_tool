<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AiTextService — single admin-configurable entry point for every "send a
 * prompt, get text back" flow (script/scene generation, text/SRT
 * translation). Talks to whatever OpenAI-chat-completions-compatible
 * endpoint is set in Admin > Tool Settings (SystemSetting ai_text_*)
 * instead of a hardcoded provider/model baked into each caller.
 */
class AiTextService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->baseUrl = SystemSetting::getAiTextBaseUrl();
        $this->apiKey = SystemSetting::getAiTextApiKey();
        $this->model = SystemSetting::getAiTextModel();
    }

    public function complete(string $prompt, float $temperature = 0.3): string
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('AI text API key not configured. Please set it in Admin > Tool Settings.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => $temperature,
            ]);

            if ($response->failed()) {
                $error = $response->json('error.message', $response->body());
                Log::error('AiTextService: provider error', ['status' => $response->status(), 'error' => $error]);
                throw new \RuntimeException("AI text provider error: {$error}");
            }

            $result = $response->json('choices.0.message.content');

            if (empty($result)) {
                throw new \RuntimeException('AI text provider returned empty response.');
            }

            $result = trim($result);
            $result = preg_replace('/^```(?:\w+)?\n(.*)\n```$/s', '$1', $result);

            return trim($result);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('AiTextService: connection error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Cannot connect to AI text provider. Please try again later.');
        }
    }

    public function translate(string $text, string $targetLanguage, string $format = 'text', string $context = ''): string
    {
        return $this->complete($this->buildTranslatePrompt($text, $targetLanguage, $format, $context), 0.3);
    }

    /**
     * Rewrites $text (already in $targetLanguage) so it can be spoken aloud
     * within $maxSeconds. Used when a dubbing subtitle segment's translation
     * still doesn't fit its time window after SrtTimeRedistributionService has
     * exhausted timestamp-borrowing — the last resort before either an
     * unnaturally long segment or desynced audio.
     */
    public function condenseToFit(string $text, string $targetLanguage, float $maxSeconds): string
    {
        $charBudget = (int) floor($maxSeconds * 14);

        $prompt = <<<PROMPT
The following {$targetLanguage} sentence is dubbing/subtitle text that must be spoken aloud within {$maxSeconds} seconds (roughly {$charBudget} characters at a natural speaking pace). It is currently too long to fit.

Rewrite it to fit within that time budget:
- Preserve the original meaning as closely as possible.
- Use natural, conversational {$targetLanguage}, suitable for voice-over narration.
- Prefer shorter synonyms and cut non-essential words rather than dropping key information.
- Return ONLY the rewritten sentence — no explanations, no quotes.

Text to shorten:
{$text}
PROMPT;

        return $this->complete($prompt, 0.3);
    }

    protected function buildTranslatePrompt(string $text, string $targetLanguage, string $format, string $context = ''): string
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
