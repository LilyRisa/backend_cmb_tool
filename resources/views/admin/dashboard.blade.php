@extends('admin.layout')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3 mb-4">
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-users',
    'value' => $totalUsers,
    'label' => 'Total Users',
    'variant' => 'primary',
    'subtitle' => '+' . $newUsersToday . ' hôm nay',
    'link' => route('admin.users.index'),
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-crown',
    'value' => $premiumUsers,
    'label' => 'Premium Users',
    'variant' => 'purple',
    'subtitle' => round($totalUsers > 0 ? ($premiumUsers / $totalUsers) * 100 : 0, 1) . '% tổng users',
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-coins',
    'value' => number_format($creditsToday),
    'label' => 'Credits Hôm Nay',
    'variant' => 'warning',
    'subtitle' => 'Tuần: ' . number_format($creditsThisWeek) . ' · Tháng: ' . number_format($creditsThisMonth),
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-headphones',
    'value' => $totalTtsRequests,
    'label' => 'TTS Requests',
    'variant' => 'info',
    'subtitle' => '+' . $ttsToday . ' hôm nay',
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-language',
    'value' => $totalVideoDubJobs,
    'label' => 'Video Dub Jobs',
    'variant' => 'teal',
    'subtitle' => '+' . $videoDubToday . ' hôm nay',
    'link' => route('admin.videodub.index'),
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-money-bill-wave',
    'value' => $pendingTopups,
    'label' => 'Pending Topups',
    'variant' => $pendingTopups > 0 ? 'danger' : 'success',
    'subtitle' => $pendingTopups > 0 ? 'Cần xử lý' : 'Không có',
    'link' => route('admin.analytics.topups'),
    ])
</div>

{{-- Trend charts: three single-axis charts rather than one dual-axis chart
     (a shared axis would force credits and request counts onto scales that
     don't share a unit, misleading whichever series gets the secondary axis). --}}
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-coins"></i> Credits Used (30 ngày)
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="creditChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-headphones"></i> TTS Requests (30 ngày)
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="ttsChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-sign-in-alt"></i> Login Activity (30 ngày)
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="loginChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-trophy"></i> Top Users (Credit Usage)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th class="text-end">Credits</th>
                                <th class="text-end">TTS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topUsers as $user)
                            <tr>
                                <td>
                                    <div style="font-weight:500;">{{ $user->name }}</div>
                                    <small class="text-muted">{{ $user->email }}</small>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-warning text-dark">{{ number_format($user->total_credits_used) }}</span>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-info">{{ number_format($user->total_tts_requests) }}</span>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">Chưa có dữ liệu</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-clock"></i> Recent Logins
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>IP</th>
                                <th>Thời gian</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentLogins as $log)
                            <tr>
                                <td>
                                    <span style="font-weight:500;">{{ $log->user->name ?? 'N/A' }}</span>
                                </td>
                                <td><code style="font-size:12px;">{{ $log->ip_address }}</code></td>
                                <td>
                                    <small class="text-muted">{{ $log->created_at->diffForHumans() }}</small>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">Chưa có dữ liệu</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-shield-alt"></i> IP Nghi Vấn
                @if($suspiciousIPs->count() > 0)
                <span class="badge bg-danger ms-auto">{{ $suspiciousIPs->count() }}</span>
                @endif
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th class="text-center">Users</th>
                                <th>Lần cuối</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($suspiciousIPs as $ip)
                            <tr style="cursor:pointer;" onclick="window.location='{{ route('admin.analytics.ip.detail', $ip->ip_address) }}'">
                                <td><code style="font-size:12px;">{{ $ip->ip_address }}</code></td>
                                <td class="text-center">
                                    @if($ip->user_count >= 4)
                                    <span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>{{ $ip->user_count }}</span>
                                    @else
                                    <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle me-1"></i>{{ $ip->user_count }}</span>
                                    @endif
                                </td>
                                <td>
                                    <small class="text-muted">{{ \Carbon\Carbon::parse($ip->last_seen)->diffForHumans() }}</small>
                                </td>
                                <td><i class="fas fa-eye text-muted"></i></td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="text-center py-4">
                                    <span class="text-success"><i class="fas fa-check-circle me-1"></i>Không phát hiện IP nghi vấn</span>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-fire"></i> Top Features
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Feature</th>
                                <th class="text-end">Lượt dùng</th>
                                <th class="text-end">Users</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($topFeatures as $i => $f)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td style="font-weight:500;">{{ $f->feature_name }}</td>
                                <td class="text-end">
                                    <span class="badge bg-primary">{{ number_format($f->total_usage) }}</span>
                                </td>
                                <td class="text-end">
                                    <span class="badge bg-info">{{ number_format($f->unique_users) }}</span>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">Chưa có dữ liệu</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-chart-bar"></i> Feature Usage Chart
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="featureChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Palette: validated categorical slots (blue #2a78d6 / orange #eb6834) —
        // see the dataviz skill's palette.md. Single-series charts each get one
        // hue; no adjacent-pair concern since they never share a canvas.
        const chartLabels = @json($chartLabels);

        new Chart(document.getElementById('creditChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Credits Used',
                    data: @json($creditData),
                    borderColor: '#eda100',
                    backgroundColor: 'rgba(237, 161, 0, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' } },
                },
            },
        });

        new Chart(document.getElementById('ttsChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'TTS Requests',
                    data: @json($ttsData),
                    borderColor: '#1baf7a',
                    backgroundColor: 'rgba(27, 175, 122, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(0,0,0,0.06)' } },
                },
            },
        });

        new Chart(document.getElementById('loginChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Logins',
                    data: @json($loginData),
                    backgroundColor: 'rgba(42, 120, 214, 0.7)',
                    borderColor: '#2a78d6',
                    borderWidth: 1,
                    borderRadius: 4,
                    maxBarThickness: 24,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: 'rgba(0,0,0,0.06)' } },
                },
            },
        });

        const featureChartEl = document.getElementById('featureChart');
        if (featureChartEl) {
            new Chart(featureChartEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: @json($topFeatures->pluck('feature_name')),
                    datasets: [
                        {
                            label: 'Lượt dùng',
                            data: @json($topFeatures->pluck('total_usage')),
                            backgroundColor: 'rgba(42, 120, 214, 0.8)',
                            borderColor: '#2a78d6',
                            borderWidth: 1,
                            borderRadius: 4,
                            maxBarThickness: 24,
                        },
                        {
                            label: 'Unique Users',
                            data: @json($topFeatures->pluck('unique_users')),
                            backgroundColor: 'rgba(235, 104, 52, 0.8)',
                            borderColor: '#eb6834',
                            borderWidth: 1,
                            borderRadius: 4,
                            maxBarThickness: 24,
                        },
                    ],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' } },
                        y: { grid: { display: false } },
                    },
                },
            });
        }
    });
</script>
@endpush
