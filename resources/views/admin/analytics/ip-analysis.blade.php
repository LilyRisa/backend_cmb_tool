@extends('admin.layout')

@section('title', 'IP Fraud Detection')
@section('page-title', 'IP Analysis')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'IP Fraud Detection']]])
@endsection

@section('content')
{{-- Stats --}}
<div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-shield-alt', 'value' => $totalSuspicious,
    'label' => 'IP Nghi Vấn (≥2 Users)', 'variant' => 'warning',
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-exclamation-triangle', 'value' => $highRisk,
    'label' => 'Nguy Hiểm (≥4 Users)', 'variant' => 'danger',
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-check-circle',
    'value' => $totalSuspicious == 0 ? 'An toàn' : 'Cần kiểm tra',
    'label' => 'Trạng thái', 'variant' => $totalSuspicious == 0 ? 'success' : 'warning',
    ])
</div>

{{-- Search --}}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.analytics.ip') }}" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Tìm IP</label>
                <input type="text" name="search" class="form-control" placeholder="Nhập IP address..."
                    value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label">Tối thiểu Users</label>
                <select name="min_users" class="form-select">
                    <option value="2" {{ request('min_users', 2) == 2 ? 'selected' : '' }}>≥ 2 users</option>
                    <option value="3" {{ request('min_users') == 3 ? 'selected' : '' }}>≥ 3 users</option>
                    <option value="4" {{ request('min_users') == 4 ? 'selected' : '' }}>≥ 4 users</option>
                    <option value="1" {{ request('min_users') == 1 ? 'selected' : '' }}>Tất cả IPs</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search me-1"></i>Tìm
                </button>
            </div>
            <div class="col-md-2">
                <a href="{{ route('admin.analytics.ip') }}" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>
</div>

{{-- IP Table --}}
<div class="card">
    <div class="card-header">
        <i class="fas fa-shield-alt"></i> IP Addresses ({{ $ips->total() }})
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th class="text-center">Số Users</th>
                        <th class="text-center">Tổng Đăng Ký</th>
                        <th>Lần truy cập cuối</th>
                        <th>Cảnh báo</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($ips as $ip)
                    <tr>
                        <td><code style="font-size:13px;">{{ $ip->ip_address }}</code></td>
                        <td class="text-center">
                            <strong>{{ $ip->user_count }}</strong>
                        </td>
                        <td class="text-center">{{ number_format($ip->register_count) }}</td>
                        <td><small>{{ \Carbon\Carbon::parse($ip->last_seen)->diffForHumans() }}</small></td>
                        <td>
                            @if($ip->user_count >= 4)
                            <span class="badge badge-fraud-high">
                                <i class="fas fa-exclamation-triangle me-1"></i>Nghi gian lận
                            </span>
                            @elseif($ip->user_count >= 2)
                            <span class="badge badge-fraud-low">
                                <i class="fas fa-exclamation-circle me-1"></i>Cần kiểm tra
                            </span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.analytics.ip.detail', urlencode($ip->ip_address)) }}"
                                class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i> Chi tiết
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6">
                            @include('admin._partials._empty-state', [
                            'icon' => 'fas fa-check-circle',
                            'message' => 'Không tìm thấy IP nghi vấn',
                            ])
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($ips->hasPages())
    <div class="card-footer d-flex justify-content-center">
        {{ $ips->links() }}
    </div>
    @endif
</div>
@endsection
