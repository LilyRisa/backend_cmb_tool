<?php

namespace Tests\Unit;

use App\Models\BugReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BugReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_screenshots_cast_to_array(): void
    {
        $report = BugReport::factory()->create([
            'screenshots' => ['https://example.test/a.png', 'https://example.test/b.png'],
        ]);

        $this->assertIsArray($report->fresh()->screenshots);
        $this->assertCount(2, $report->fresh()->screenshots);
    }

    public function test_user_deletion_preserves_the_bug_report_with_null_user_id(): void
    {
        $user = User::factory()->create();
        $report = BugReport::factory()->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertNull($report->fresh()->user_id);
    }
}
