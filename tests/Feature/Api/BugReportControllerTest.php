<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BugReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_submit_creates_a_bug_report_without_screenshots(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/bug-reports', [
                'description' => 'Không upload tự động được video tiktok',
                'app_version' => '4.2.1',
            ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('bug_reports', ['user_id' => $user->id, 'app_version' => '4.2.1', 'status' => 'pending']);
    }

    public function test_submit_uploads_screenshots_to_local_storage(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('screenshot.png');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/bug-reports', [
                'description' => 'Bug with screenshot',
                'screenshots' => [$file],
            ]);

        $response->assertStatus(201);
        $report = \App\Models\BugReport::first();
        $this->assertEquals(1, $report->screenshot_count);
        $this->assertStringContainsString('/storage/bug-reports/', $report->screenshots[0]);
    }

    public function test_submit_requires_description(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/bug-reports', [])
            ->assertStatus(422);
    }

    public function test_submit_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/bug-reports', ['description' => 'x'])->assertStatus(401);
    }
}
