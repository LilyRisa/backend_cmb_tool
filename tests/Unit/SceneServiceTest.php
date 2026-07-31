<?php

namespace Tests\Unit;

use App\Services\SceneService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SceneServiceTest extends TestCase
{
    public function test_generate_scenes_returns_scenes_with_proportional_durations(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                ['text' => 'A person walks into a bright office.', 'visual_description' => 'person walking into office', 'duration_weight' => 2],
                ['text' => 'They sit down and open a laptop.', 'visual_description' => 'person sitting with laptop', 'duration_weight' => 3],
                ['text' => 'The screen shows a big success message.', 'visual_description' => 'success message on screen', 'duration_weight' => 5],
            ])]]],
        ], 200)]);

        $service = new SceneService();
        $result = $service->generateScenes(
            'A person walks into a bright office. They sit down and open a laptop. The screen shows a big success message.',
            'lạc quan',
            30
        );

        $this->assertEquals(3, $result['total_scenes']);
        $this->assertEquals(30, $result['total_duration']);
        $this->assertCount(3, $result['scenes']);
        // Proportional: weights 2/3/5 sum to 10 -> 6s/9s/15s (last gets remainder)
        $this->assertEquals(6, $result['scenes'][0]['duration']);
        $this->assertEquals(9, $result['scenes'][1]['duration']);
        $this->assertEquals(15, $result['scenes'][2]['duration']);
        $this->assertEquals(1, $result['scenes'][0]['index']);
        $this->assertArrayHasKey('visual_description', $result['scenes'][0]);
    }

    public function test_generate_scenes_strips_markdown_fences_from_ai_response(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => "```json\n" . json_encode([
                ['text' => 'One scene only.', 'visual_description' => 'a scene', 'duration_weight' => 3],
            ]) . "\n```"]]],
        ], 200)]);

        $service = new SceneService();
        $result = $service->generateScenes('One scene only.', 'nghiêm túc', 15);

        $this->assertEquals(1, $result['total_scenes']);
        $this->assertEquals('One scene only.', $result['scenes'][0]['text']);
    }

    public function test_generate_scenes_throws_when_ai_returns_invalid_json(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'not valid json at all']]]], 200)]);

        $service = new SceneService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI returned invalid scene data. Please try again.');

        $service->generateScenes('Some script text.', 'hài hước', 15);
    }

    public function test_generate_scenes_throws_after_exhausting_retries_on_persistent_failure(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'server error']], 500)]);

        $service = new SceneService();

        $this->expectException(\RuntimeException::class);

        $service->generateScenes('Some script text.', 'hài hước', 15);
    }
}
