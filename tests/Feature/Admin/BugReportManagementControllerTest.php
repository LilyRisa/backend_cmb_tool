<?php

namespace Tests\Feature\Admin;

use App\Models\BugReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BugReportManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_index_lists_bug_reports(): void
    {
        BugReport::factory()->count(2)->create();

        $response = $this->actingAsAdmin()->get('/admin/bug-reports');

        $response->assertOk();
        $response->assertViewHas('reports', fn ($r) => $r->total() === 2);
    }

    public function test_update_status_changes_status(): void
    {
        $report = BugReport::factory()->create(['status' => 'pending']);

        $response = $this->actingAsAdmin()->put("/admin/bug-reports/{$report->id}", ['status' => 'resolved']);

        $response->assertRedirect(route('admin.bug-reports.index'));
        $this->assertEquals('resolved', $report->fresh()->status);
    }

    public function test_index_rejects_unauthenticated_requests(): void
    {
        $this->get('/admin/bug-reports')->assertRedirect();
    }
}
