@extends('admin.layout')

@section('title', 'Tool Statistics')
@section('page-title', 'Tool TTS - Thống kê sử dụng')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Tool Statistics']]])
@endsection

@section('content')

{{-- Overview Stats --}}
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card primary">
            <p><i class="fas fa-users"></i> Tổng Users</p>
            <h3>{{ number_format($totalUsers) }}</h3>
            <small>{{ $premiumUsers }} premium</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card success">
            <p><i class="fas fa-microphone"></i> Tổng TTS Requests</p>
            <h3>{{ number_format($totalTtsRequests) }}</h3>
            <small>{{ $completedTtsRequests }} hoàn thành</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card info">
            <p><i class="fas fa-coins"></i> Tổng Credits đã dùng</p>
            <h3>{{ number_format($totalCreditsUsed) }}</h3>
            <small>{{ number_format($totalCreditsRefunded) }} đã hoàn</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stats-card warning">
            <p><i class="fas fa-bolt"></i> Hôm nay</p>
            <h3>{{ number_format($creditsToday) }}</h3>
            <small>{{ $ttsToday }} requests</small>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted">Credits tuần này</h6>
                <h4 class="text-primary">{{ number_format($creditsThisWeek) }}</h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted">Credits tháng này</h6>
                <h4 class="text-primary">{{ number_format($creditsThisMonth) }}</h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted">Tỉ lệ hoàn thành</h6>
                <h4 class="text-success">
                    {{ $totalTtsRequests > 0 ? round($completedTtsRequests / $totalTtsRequests * 100, 1) : 0 }}%
                </h4>
            </div>
        </div>
    </div>
</div>

<div class="row">
    {{-- Top Users --}}
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-trophy me-2"></i>Top Users theo Credit Usage
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Package</th>
                            <th>Credits còn</th>
                            <th>Credits đã dùng</th>
                            <th>TTS Requests</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($topUsers as $i => $u)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>
                                <strong>{{ $u->name }}</strong><br>
                                <small class="text-muted">{{ $u->email }}</small>
                            </td>
                            <td>
                                @if(!$u->isPremium())
                                <span class="badge bg-secondary">{{ $u->package_type === 'free' ? 'Free' : ucfirst($u->package_type) . ' (Hết hạn)' }}</span>
                                @else
                                <span class="badge bg-{{ $u->package_type === 'premium' ? 'success' : 'primary' }}">
                                    {{ ucfirst($u->package_type) }}
                                </span>
                                @endif
                            </td>
                            <td>{{ number_format($u->credits) }}</td>
                            <td>{{ number_format($u->total_credits_used ?? 0) }}</td>
                            <td>{{ number_format($u->total_tts_requests ?? 0) }}</td>
                            <td>
                                <a href="{{ route('admin.tool-stats.user', $u->id) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Chưa có dữ liệu</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Recent Login Activity --}}
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-sign-in-alt me-2"></i>Login gần đây
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Action</th>
                            <th>IP</th>
                            <th>Thời gian</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentLogins as $log)
                        <tr>
                            <td>{{ $log->user->name ?? 'N/A' }}</td>
                            <td>
                                <span class="badge bg-{{ $log->action === 'login' ? 'info' : 'success' }}">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td><code>{{ $log->ip_address }}</code></td>
                            <td><small>{{ $log->created_at->diffForHumans() }}</small></td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-3">Chưa có log</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
