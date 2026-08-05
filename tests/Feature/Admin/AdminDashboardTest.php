<?php

namespace Tests\Feature\Admin;

use App\Models\CreditTransaction;
use App\Models\FeatureUsage;
use App\Models\LoginLog;
use App\Models\PendingCreditTopup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_returns_all_expected_data_keys(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertViewHasAll([
            'totalUsers', 'premiumUsers', 'newUsersToday',
            'creditsToday', 'creditsThisWeek', 'creditsThisMonth',
            'totalTtsRequests', 'ttsToday',
            'totalVideoDubJobs', 'videoDubToday',
            'pendingTopups',
            'chartLabels', 'creditData', 'ttsData', 'loginData',
            'topUsers', 'recentLogins', 'suspiciousIPs', 'topFeatures',
        ]);
    }

    public function test_chart_arrays_cover_a_fixed_30_day_window(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk();
        $this->assertCount(30, $response->viewData('chartLabels'));
        $this->assertCount(30, $response->viewData('creditData'));
        $this->assertCount(30, $response->viewData('ttsData'));
        $this->assertCount(30, $response->viewData('loginData'));
    }

    public function test_credits_today_only_counts_todays_deductions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00')); // a Wednesday, safely mid-week

        $user = User::factory()->create();

        CreditTransaction::create([
            'user_id' => $user->id, 'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -50, 'balance_after' => 0, 'description' => 'today',
        ]);
        (new CreditTransaction())->forceFill([
            'user_id' => $user->id, 'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -30, 'balance_after' => 0, 'description' => 'yesterday',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ])->save();

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk()->assertViewHas('creditsToday', 50);
        $response->assertViewHas('creditsThisWeek', 80);
    }

    public function test_top_users_ranks_by_total_credit_usage_descending(): void
    {
        $heavy = User::factory()->create(['name' => 'Heavy User']);
        $light = User::factory()->create(['name' => 'Light User']);

        CreditTransaction::create([
            'user_id' => $heavy->id, 'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -500, 'balance_after' => 0, 'description' => 'x',
        ]);
        CreditTransaction::create([
            'user_id' => $light->id, 'type' => CreditTransaction::TYPE_DEDUCT,
            'amount' => -10, 'balance_after' => 0, 'description' => 'x',
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $topUsers = $response->viewData('topUsers');
        $this->assertSame($heavy->id, $topUsers->first()->id);
        $this->assertEquals(500, $topUsers->first()->total_credits_used);
    }

    public function test_suspicious_ips_flags_ips_shared_by_multiple_registered_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        LoginLog::record($userA->id, LoginLog::ACTION_REGISTER, '203.0.113.5');
        LoginLog::record($userB->id, LoginLog::ACTION_REGISTER, '203.0.113.5');

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $suspiciousIPs = $response->viewData('suspiciousIPs');
        $this->assertTrue($suspiciousIPs->contains(fn ($ip) => $ip->ip_address === '203.0.113.5' && $ip->user_count == 2));
    }

    public function test_top_features_aggregates_usage_across_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        FeatureUsage::create(['user_id' => $userA->id, 'feature_name' => 'tts', 'usage_count' => 7, 'last_used_at' => now()]);
        FeatureUsage::create(['user_id' => $userB->id, 'feature_name' => 'tts', 'usage_count' => 3, 'last_used_at' => now()]);

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $topFeatures = $response->viewData('topFeatures');
        $tts = $topFeatures->firstWhere('feature_name', 'tts');
        $this->assertEquals(10, $tts->total_usage);
        $this->assertEquals(2, $tts->unique_users);
    }

    public function test_pending_topups_counts_only_pending_status(): void
    {
        $user = User::factory()->create();
        PendingCreditTopup::create([
            'user_id' => $user->id, 'package_id' => 'pkg_100k', 'credits' => 1000,
            'transaction_code' => 'TXN1', 'amount' => 100_000,
            'status' => PendingCreditTopup::STATUS_PENDING,
        ]);
        PendingCreditTopup::create([
            'user_id' => $user->id, 'package_id' => 'pkg_100k', 'credits' => 1000,
            'transaction_code' => 'TXN2', 'amount' => 100_000,
            'status' => PendingCreditTopup::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.dashboard'));

        $response->assertOk()->assertViewHas('pendingTopups', 1);
    }
}
