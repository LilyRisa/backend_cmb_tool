@extends('admin.layout')

@section('title', 'Credit Top-ups')
@section('page-title', 'Credit Top-up History')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Credit Top-ups']]])
@endsection

@section('content')
{{-- Stats --}}
<div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-hourglass-half', 'value' => $totalPending,
    'label' => 'Đang Chờ', 'variant' => $totalPending > 0 ? 'warning' : 'success',
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-check-circle', 'value' => $totalCompleted,
    'label' => 'Đã Hoàn Thành', 'variant' => 'success',
    ])
    @include('admin._partials._stats-card', [
    'icon' => 'fas fa-money-bill-wave', 'value' => number_format($totalRevenue) . ' đ',
    'label' => 'Tổng Doanh Thu', 'variant' => 'primary',
    ])
</div>

{{-- Filter --}}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.analytics.topups') }}" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Tìm kiếm</label>
                <input type="text" name="search" class="form-control"
                    placeholder="Mã giao dịch hoặc tên user..."
                    value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label">Trạng thái</label>
                <select name="status" class="form-select">
                    <option value="all">Tất cả</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Đang chờ</option>
                    <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Hoàn thành</option>
                    <option value="expired" {{ request('status') == 'expired' ? 'selected' : '' }}>Hết hạn</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search me-1"></i>Tìm
                </button>
            </div>
            <div class="col-md-2">
                <a href="{{ route('admin.analytics.topups') }}" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>
</div>

{{-- Table --}}
<div class="card">
    <div class="card-header">
        <i class="fas fa-coins"></i> Top-up Transactions ({{ $topups->total() }})
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Gói</th>
                        <th class="text-end">Credits</th>
                        <th class="text-end">Số tiền</th>
                        <th>Mã GD</th>
                        <th>Trạng thái</th>
                        <th>Tạo lúc</th>
                        <th>Hoàn thành</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topups as $t)
                    <tr>
                        <td>
                            @if($t->user)
                            <div style="font-weight:500;">{{ $t->user->name }}</div>
                            <small class="text-muted">{{ $t->user->email }}</small>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td><small>{{ $t->package_id ?? '—' }}</small></td>
                        <td class="text-end"><strong>{{ number_format($t->credits ?? 0) }}</strong></td>
                        <td class="text-end">{{ number_format($t->amount ?? 0) }} đ</td>
                        <td><code style="font-size:12px;">{{ $t->transaction_code ?? '—' }}</code></td>
                        <td>
                            @if($t->status === 'pending')
                            <span class="badge bg-warning text-dark">Đang chờ</span>
                            @elseif($t->status === 'completed')
                            <span class="badge bg-success">Hoàn thành</span>
                            @elseif($t->status === 'expired')
                            <span class="badge bg-danger">Hết hạn</span>
                            @else
                            <span class="badge bg-secondary">{{ $t->status }}</span>
                            @endif
                        </td>
                        <td><small>{{ $t->created_at->format('d/m H:i') }}</small></td>
                        <td><small>{{ $t->completed_at ? \Carbon\Carbon::parse($t->completed_at)->format('d/m H:i') : '—' }}</small></td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8">
                            @include('admin._partials._empty-state', ['message' => 'Không có top-up nào'])
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($topups->hasPages())
    <div class="card-footer d-flex justify-content-center">
        {{ $topups->links() }}
    </div>
    @endif
</div>
@endsection
