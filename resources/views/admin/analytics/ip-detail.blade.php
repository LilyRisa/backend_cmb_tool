@extends('admin.layout')

@section('title', 'IP: ' . $ip)
@section('page-title', 'IP Detail')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [
['label' => 'IP Fraud Detection', 'url' => route('admin.analytics.ip')],
['label' => $ip],
]])
@endsection

@section('content')
{{-- IP Info --}}
<div class="card mb-4">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4 class="mb-1"><code>{{ $ip }}</code></h4>
                <p class="text-muted mb-0">
                    <strong>{{ $users->count() }}</strong> tài khoản · <strong>{{ $totalRegistrations }}</strong> lần đăng ký
                </p>
            </div>
            <div class="col-md-6 text-md-end mt-3 mt-md-0">
                @if($users->count() >= 4)
                <span class="badge badge-fraud-high" style="font-size:14px;padding:8px 16px;">
                    <i class="fas fa-exclamation-triangle me-1"></i>Nghi gian lận
                </span>
                @elseif($users->count() >= 2)
                <span class="badge badge-fraud-low" style="font-size:14px;padding:8px 16px;">
                    <i class="fas fa-exclamation-circle me-1"></i>Cần kiểm tra
                </span>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Users from this IP --}}
<div class="card mb-4">
    <div class="card-header">
        <i class="fas fa-users"></i> Tài khoản từ IP này ({{ $users->count() }})
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th class="text-center">Lần ĐK</th>
                        <th>Đăng ký lúc</th>
                        <th>Lần cuối</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $u)
                    <tr>
                        <td>
                            @if($u->user)
                            <div style="font-weight:500;">{{ $u->user->name }}</div>
                            <small class="text-muted">{{ $u->user->email }}</small>
                            @else
                            <span class="text-muted">User #{{ $u->user_id }} (đã xóa)</span>
                            @endif
                        </td>
                        <td class="text-center"><strong>{{ $u->register_count }}</strong></td>
                        <td><small>{{ \Carbon\Carbon::parse($u->first_seen)->format('d/m/Y H:i') }}</small></td>
                        <td><small>{{ \Carbon\Carbon::parse($u->last_seen)->diffForHumans() }}</small></td>
                        <td>
                            @if($u->user)
                            <a href="{{ route('admin.tool-stats.user', $u->user_id) }}" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye"></i>
                            </a>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Login Timeline --}}
<div class="card">
    <div class="card-header">
        <i class="fas fa-clock"></i> Lịch Sử Đăng Ký
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>User Agent</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($timeline as $log)
                    <tr>
                        <td><small>{{ $log->created_at->format('d/m/Y H:i:s') }}</small></td>
                        <td>
                            @if($log->user)
                            <a href="{{ route('admin.tool-stats.user', $log->user_id) }}" class="text-decoration-none">
                                {{ $log->user->name }}
                            </a>
                            @else
                            <span class="text-muted">User #{{ $log->user_id }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-info">đăng ký</span>
                        </td>
                        <td><small class="text-muted" style="max-width:250px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $log->user_agent ?? '—' }}</small></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @if($timeline->hasPages())
    <div class="card-footer d-flex justify-content-center">
        {{ $timeline->links() }}
    </div>
    @endif
</div>
@endsection
