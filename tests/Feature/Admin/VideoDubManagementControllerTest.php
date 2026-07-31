<?php

namespace Tests\Feature\Admin;

use App\Models\TtsHistory;
use App\Models\User;
use App\Models\VideoDubJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoDubManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_index_lists_jobs_with_stats(): void
    {
        $user = User::factory()->create();
        VideoDubJob::factory()->count(2)->create(['user_id' => $user->id, 'status' => 'completed', 'credits_deducted' => 10, 'characters_used' => 100]);
        VideoDubJob::factory()->create(['user_id' => $user->id, 'status' => 'failed']);

        $response = $this->actingAsAdmin()->get('/admin/videodub');

        $response->assertOk();
        $response->assertViewHas('stats', function ($stats) {
            return $stats['total'] === 3 && $stats['completed'] === 2 && $stats['failed'] === 1;
        });
    }

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create();
        VideoDubJob::factory()->create(['user_id' => $user->id, 'status' => 'completed']);
        VideoDubJob::factory()->create(['user_id' => $user->id, 'status' => 'failed']);

        $response = $this->actingAsAdmin()->get('/admin/videodub?status=failed');

        $response->assertOk();
        $jobs = $response->viewData('jobs');
        $this->assertCount(1, $jobs);
        $this->assertEquals('failed', $jobs->first()->status);
    }

    public function test_show_displays_job_detail_with_linked_tts_stats(): void
    {
        $user = User::factory()->create();
        $history = TtsHistory::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'characters_used' => 50, 'credits_deducted_user' => 5]);
        $job = VideoDubJob::factory()->create(['user_id' => $user->id, 'tts_task_ids' => [$history->id]]);

        $response = $this->actingAsAdmin()->get("/admin/videodub/{$job->id}");

        $response->assertOk();
        $response->assertViewHas('ttsStats', function ($stats) {
            return $stats['total'] === 1 && $stats['completed'] === 1 && $stats['total_characters'] === 50 && $stats['total_credits'] === 5;
        });
    }

    public function test_show_404s_for_unknown_job(): void
    {
        $this->actingAsAdmin()->get('/admin/videodub/999999')->assertStatus(404);
    }

    public function test_index_and_show_reject_unauthenticated_requests(): void
    {
        $this->get('/admin/videodub')->assertRedirect();

        $user = User::factory()->create();
        $job = VideoDubJob::factory()->create(['user_id' => $user->id]);
        $this->get("/admin/videodub/{$job->id}")->assertRedirect();
    }
}
