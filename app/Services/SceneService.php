<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SceneService — splits a full script into visual scenes using Gemini AI.
 *
 * Pipeline:
 *   1. Smart chunking (sentence-boundary aware, ~800 words per chunk)
 *   2. Gemini AI extracts scenes from each chunk (grouped visual ideas)
 *   3. Merge scene lists + deduplicate boundary overlaps
 *   4. Post-process: consolidate scenes to match target count
 *   5. Proportional duration calculation to match total_duration
 *
 * Handles scripts up to ~3000 words (~20 min reading time).
 */
class SceneService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://openrouter.ai/api/v1';
    protected string $model   = 'google/gemini-2.0-flash-001';

    private const MAX_WORDS_PER_CHUNK = 800;
    private const OVERLAP_SENTENCES = 2;
    private const TARGET_SCENE_DURATION = 15;
    private const MIN_SCENE_DURATION = 5;
    private const MAX_SCENE_DURATION = 20;
    private const MIN_SCENES = 3;
    private const MAX_SCENES = 30;
    private const MAX_RETRIES = 2;
    private const RETRY_DELAY = 2;
    private const OVERLAP_SIMILARITY_THRESHOLD = 0.5;
    private const CONSOLIDATION_SIMILARITY_THRESHOLD = 0.3;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key');
    }

    public function generateScenes(
        string $script,
        string $context,
        int    $totalDuration,
    ): array {
        $targetSceneCount = $this->calculateTargetSceneCount($totalDuration);

        $chunks = $this->splitIntoChunks($script);

        Log::info('SceneService: starting scene generation', [
            'total_words' => $this->countWords($script),
            'chunks' => count($chunks),
            'total_duration' => $totalDuration,
            'target_scene_count' => $targetSceneCount,
            'context' => $context,
        ]);

        $allScenes = [];
        $previousTailSentences = '';
        $totalWords = $this->countWords($script);

        foreach ($chunks as $i => $chunk) {
            $isFirst = ($i === 0);
            $isLast  = ($i === count($chunks) - 1);

            $chunkWords = $this->countWords($chunk);
            $chunkTargetScenes = max(2, (int) round(
                $targetSceneCount * ($chunkWords / max(1, $totalWords))
            ));

            $scenes = $this->extractScenesFromChunk(
                chunk:            $chunk,
                context:           $context,
                chunkIndex:        $i,
                totalChunks:       count($chunks),
                isFirst:           $isFirst,
                isLast:            $isLast,
                overlapContext:    $previousTailSentences,
                targetSceneCount:  $chunkTargetScenes,
                totalDuration:     $totalDuration,
            );

            Log::info("SceneService: chunk " . ($i + 1) . "/" . count($chunks) . " extracted", [
                'scenes_count' => count($scenes),
                'chunk_target' => $chunkTargetScenes,
            ]);

            $allScenes[] = $scenes;

            $sentences = $this->splitSentences($chunk);
            $previousTailSentences = implode(' ', array_slice($sentences, -self::OVERLAP_SENTENCES));
        }

        $mergedScenes = $this->mergeSceneLists($allScenes);

        Log::info('SceneService: scenes merged', ['total_scenes' => count($mergedScenes)]);

        $consolidatedScenes = $this->consolidateScenes($mergedScenes, $targetSceneCount);

        Log::info('SceneService: scenes consolidated', [
            'before' => count($mergedScenes),
            'after'  => count($consolidatedScenes),
            'target' => $targetSceneCount,
        ]);

        $finalScenes = $this->calculateDurations($consolidatedScenes, $totalDuration);

        return [
            'total_scenes'   => count($finalScenes),
            'total_duration' => $totalDuration,
            'scenes'         => $finalScenes,
        ];
    }

    private function calculateTargetSceneCount(int $totalDuration): int
    {
        $target = (int) round($totalDuration / self::TARGET_SCENE_DURATION);

        return max(self::MIN_SCENES, min(self::MAX_SCENES, $target));
    }

    private function splitIntoChunks(string $script): array
    {
        $sentences = $this->splitSentences($script);

        if (empty($sentences)) {
            return [$script];
        }

        $chunks = [];
        $currentChunk = [];
        $currentWordCount = 0;

        foreach ($sentences as $sentence) {
            $sentenceWords = $this->countWords($sentence);

            if ($currentWordCount + $sentenceWords > self::MAX_WORDS_PER_CHUNK && !empty($currentChunk)) {
                $chunks[] = implode(' ', $currentChunk);
                $currentChunk = [];
                $currentWordCount = 0;
            }

            $currentChunk[] = $sentence;
            $currentWordCount += $sentenceWords;
        }

        if (!empty($currentChunk)) {
            $chunks[] = implode(' ', $currentChunk);
        }

        return $chunks;
    }

    private function splitSentences(string $text): array
    {
        $sentences = preg_split(
            '/(?<=[.!?…。！？])\s+/u',
            trim($text),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        return array_map('trim', array_filter($sentences, fn ($s) => trim($s) !== ''));
    }

    private function extractScenesFromChunk(
        string $chunk,
        string $context,
        int    $chunkIndex,
        int    $totalChunks,
        bool   $isFirst,
        bool   $isLast,
        string $overlapContext = '',
        int    $targetSceneCount = 10,
        int    $totalDuration = 180,
    ): array {
        $prompt = $this->buildExtractionPrompt(
            $chunk, $context, $chunkIndex, $totalChunks,
            $isFirst, $isLast, $overlapContext,
            $targetSceneCount, $totalDuration,
        );

        $raw = $this->callWithRetry($prompt);

        return $this->parseSceneJson($raw);
    }

    private function buildExtractionPrompt(
        string $chunk,
        string $context,
        int    $chunkIndex,
        int    $totalChunks,
        bool   $isFirst,
        bool   $isLast,
        string $overlapContext,
        int    $targetSceneCount,
        int    $totalDuration,
    ): string {
        $contextDescriptions = [
            'thân mật'          => 'friendly, warm, intimate',
            'hài hước'          => 'humorous, funny, lighthearted',
            'nghiêm túc'        => 'serious, professional, dramatic',
            'truyền cảm hứng'   => 'inspirational, motivational, uplifting',
            'lạc quan'          => 'optimistic, bright, positive',
            'bi quan'           => 'pessimistic, dark, melancholic',
            'nhiệt tình'        => 'energetic, enthusiastic, dynamic',
        ];

        $contextEn = $contextDescriptions[$context] ?? 'neutral';
        $avgSceneDuration = (int) round($totalDuration / max(1, $targetSceneCount));

        $prompt = <<<PROMPT
You are a professional video editor who splits scripts into visual scenes for short-form video production.

TASK:
Analyze the following script text and split it into VISUAL SCENES.
Each scene should represent a coherent visual segment — group consecutive sentences about the SAME topic, location, or visual idea into ONE scene.

VIDEO DURATION: {$totalDuration} seconds total
TARGET: approximately {$targetSceneCount} scenes (each scene ≈ {$avgSceneDuration} seconds of video)
CONTENT TONE: {$context} ({$contextEn})

RULES:
1. Each scene must contain the EXACT original text from the script (do not rewrite or translate)
2. Group consecutive sentences that share the same visual theme into ONE scene — do NOT create a separate scene for every sentence
3. Only create a new scene when there is a CLEAR change in: subject, location, action, or emotion
4. For each scene, write a "visual_description" in English describing what the viewer should SEE on screen (for stock media search)
5. Assign a "duration_weight" (integer 1–5) based on the importance/length of the scene:
   - 1 = very short transition (2–3 seconds worth)
   - 2 = short scene (~5 seconds)
   - 3 = normal scene (~10-15 seconds)
   - 4 = important scene with more screen time (~15-20 seconds)
   - 5 = key moment, needs the most screen time (~20+ seconds)
6. Keep the original order of the text
7. Only split a sentence into multiple scenes if it contains CLEARLY DIFFERENT visual ideas (e.g. different locations)
8. IMPORTANT: Aim for approximately {$targetSceneCount} scenes. Creating significantly more than this is NOT desired.

OUTPUT FORMAT:
Return ONLY a valid JSON array. No markdown, no explanation, no code blocks.
Example:
[
  {"text": "original script text for scene 1...", "visual_description": "person walking in park at sunset", "duration_weight": 3},
  {"text": "original script text for scene 2...", "visual_description": "close-up of hands typing on laptop", "duration_weight": 4}
]
PROMPT;

        if ($totalChunks > 1) {
            $position = match (true) {
                $isFirst => 'BEGINNING of the script',
                $isLast  => 'END of the script',
                default  => 'MIDDLE of the script (part ' . ($chunkIndex + 1) . ' of ' . $totalChunks . ')',
            };

            $prompt .= "\n\nPOSITION: This text is from the {$position}.";

            if (!empty($overlapContext)) {
                $prompt .= "\n\nPREVIOUS CONTEXT (for reference only — do NOT include this text in your scenes):\n\"{$overlapContext}\"";
            }
        }

        $prompt .= "\n\nSCRIPT TEXT TO ANALYZE:\n{$chunk}";

        return $prompt;
    }

    private function parseSceneJson(string $raw): array
    {
        $raw = preg_replace('/^```(?:json)?\s*\n?/i', '', $raw);
        $raw = preg_replace('/\n?```\s*$/i', '', $raw);
        $raw = trim($raw);

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $fixed = preg_replace('/,\s*([}\]])/s', '$1', $raw);
            $decoded = json_decode($fixed, true);
        }

        if (!is_array($decoded)) {
            Log::warning('SceneService: failed to parse AI scene JSON', [
                'raw' => substr($raw, 0, 500),
                'json_error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException('AI returned invalid scene data. Please try again.');
        }

        $scenes = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || empty($item['text'])) {
                continue;
            }

            $scenes[] = [
                'text'               => trim($item['text']),
                'visual_description' => trim($item['visual_description'] ?? ''),
                'duration_weight'    => max(1, min(5, (int) ($item['duration_weight'] ?? 3))),
            ];
        }

        if (empty($scenes)) {
            throw new \RuntimeException('AI returned no valid scenes from chunk.');
        }

        return $scenes;
    }

    private function mergeSceneLists(array $sceneLists): array
    {
        if (count($sceneLists) === 1) {
            return $sceneLists[0];
        }

        $merged = $sceneLists[0];

        for ($i = 1; $i < count($sceneLists); $i++) {
            $currentList = $sceneLists[$i];

            if (empty($currentList)) {
                continue;
            }

            $lastScene  = end($merged);
            $firstScene = $currentList[0];

            $similarity = $this->textSimilarity($lastScene['text'], $firstScene['text']);

            if ($similarity >= self::OVERLAP_SIMILARITY_THRESHOLD) {
                Log::info('SceneService: merging overlapping boundary scenes', [
                    'similarity' => round($similarity, 2),
                    'scene_a'    => substr($lastScene['text'], 0, 60),
                    'scene_b'    => substr($firstScene['text'], 0, 60),
                ]);

                $mergedScene = [
                    'text'               => strlen($lastScene['text']) >= strlen($firstScene['text'])
                                            ? $lastScene['text']
                                            : $firstScene['text'],
                    'visual_description' => $lastScene['visual_description'] ?: $firstScene['visual_description'],
                    'duration_weight'    => max($lastScene['duration_weight'], $firstScene['duration_weight']),
                ];

                array_pop($merged);
                $merged[] = $mergedScene;

                $merged = array_merge($merged, array_slice($currentList, 1));
            } else {
                $merged = array_merge($merged, $currentList);
            }
        }

        return $merged;
    }

    private function textSimilarity(string $a, string $b): float
    {
        $wordsA = array_unique(preg_split('/\s+/u', mb_strtolower(trim($a)), -1, PREG_SPLIT_NO_EMPTY));
        $wordsB = array_unique(preg_split('/\s+/u', mb_strtolower(trim($b)), -1, PREG_SPLIT_NO_EMPTY));

        if (empty($wordsA) || empty($wordsB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($wordsA, $wordsB));
        $union        = count(array_unique(array_merge($wordsA, $wordsB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function consolidateScenes(array $scenes, int $targetCount): array
    {
        $maxAllowed = (int) ceil($targetCount * 1.3);

        if (count($scenes) <= $maxAllowed) {
            return $scenes;
        }

        Log::info('SceneService: consolidating scenes', [
            'current' => count($scenes), 'target' => $targetCount, 'max_allowed' => $maxAllowed,
        ]);

        while (count($scenes) > $targetCount && count($scenes) > self::MIN_SCENES) {
            $bestPairIndex = null;
            $bestScore = -1;

            for ($i = 0; $i < count($scenes) - 1; $i++) {
                $similarity = $this->textSimilarity(
                    $scenes[$i]['text'],
                    $scenes[$i + 1]['text']
                );

                $weightBonus = (6 - min($scenes[$i]['duration_weight'], $scenes[$i + 1]['duration_weight'])) * 0.1;

                $score = $similarity + $weightBonus;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPairIndex = $i;
                }
            }

            if ($bestPairIndex === null || $bestScore < self::CONSOLIDATION_SIMILARITY_THRESHOLD) {
                if (count($scenes) > $maxAllowed) {
                    $bestPairIndex = $this->findLowestWeightPair($scenes);
                } else {
                    break;
                }
            }

            $sceneA = $scenes[$bestPairIndex];
            $sceneB = $scenes[$bestPairIndex + 1];

            $mergedScene = [
                'text'               => $sceneA['text'] . ' ' . $sceneB['text'],
                'visual_description' => strlen($sceneA['visual_description']) >= strlen($sceneB['visual_description'])
                                        ? $sceneA['visual_description']
                                        : $sceneB['visual_description'],
                'duration_weight'    => min(5, $sceneA['duration_weight'] + $sceneB['duration_weight']),
            ];

            array_splice($scenes, $bestPairIndex, 2, [$mergedScene]);
        }

        return array_values($scenes);
    }

    private function findLowestWeightPair(array $scenes): int
    {
        $bestIndex = 0;
        $lowestWeight = PHP_INT_MAX;

        for ($i = 0; $i < count($scenes) - 1; $i++) {
            $combined = $scenes[$i]['duration_weight'] + $scenes[$i + 1]['duration_weight'];
            if ($combined < $lowestWeight) {
                $lowestWeight = $combined;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }

    private function calculateDurations(array $scenes, int $totalDuration): array
    {
        if (empty($scenes)) {
            return [];
        }

        $totalWeight = array_sum(array_column($scenes, 'duration_weight'));

        if ($totalWeight <= 0) {
            $totalWeight = count($scenes);
        }

        $result = [];
        $assignedDuration = 0;

        foreach ($scenes as $i => $scene) {
            $isLast = ($i === count($scenes) - 1);

            if ($isLast) {
                $duration = $totalDuration - $assignedDuration;
            } else {
                $rawDuration = ($scene['duration_weight'] / $totalWeight) * $totalDuration;
                $duration = (int) round($rawDuration);
            }

            $duration = max(self::MIN_SCENE_DURATION, min(self::MAX_SCENE_DURATION, $duration));

            $assignedDuration += $duration;

            $result[] = [
                'index'              => $i + 1,
                'text'               => $scene['text'],
                'visual_description' => $scene['visual_description'],
                'duration'           => $duration,
            ];
        }

        return $result;
    }

    private function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        return count(preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY));
    }

    private function callWithRetry(string $prompt): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return $this->callOpenRouter($prompt);
            // ConnectionException (cURL timeout / DNS / connection reset) is NOT a
            // RuntimeException subclass — without it here, a timed-out call escapes
            // the retry loop on the first attempt and surfaces raw "cURL error 28"
            // text to the user instead of retrying. Matches OpenRouterService.
            } catch (\RuntimeException|\Illuminate\Http\Client\ConnectionException $e) {
                $lastException = $e;
                Log::warning("SceneService: OpenRouter attempt {$attempt}/" . self::MAX_RETRIES . " failed", [
                    'error' => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY * $attempt);
                }
            }
        }

        throw new \RuntimeException(
            'Scene extraction failed after multiple attempts. ' . $lastException?->getMessage()
        );
    }

    private function callOpenRouter(string $prompt): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'HTTP-Referer'  => config('app.url'),
            'X-Title'       => config('app.name'),
        ])->timeout(120)->post("{$this->baseUrl}/chat/completions", [
            'model'    => $this->model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.3,
        ]);

        if ($response->failed()) {
            $error = $response->json('error.message', $response->body());
            throw new \RuntimeException("OpenRouter API error: {$error}");
        }

        $result = $response->json('choices.0.message.content');

        if (empty($result)) {
            throw new \RuntimeException('OpenRouter API returned empty response.');
        }

        return trim($result);
    }
}
