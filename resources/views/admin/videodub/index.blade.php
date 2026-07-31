@extends('admin.layout')

@section('title', 'Video Dub Jobs')
@section('page-title', 'Video Dub Jobs')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Video Dub Jobs']]])
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Tổng Jobs</div>
                <div class="h4 mb-0 fw-bold text-primary">{{ number_format($stats['total']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Đang xử lý</div>
                <div class="h4 mb-0 fw-bold text-warning">{{ number_format($stats['processing']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Hoàn thành</div>
                <div class="h4 mb-0 fw-bold text-success">{{ number_format($stats['completed']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Thất bại</div>
                <div class="h4 mb-0 fw-bold text-danger">{{ number_format($stats['failed']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Credits</div>
                <div class="h4 mb-0 fw-bold text-info">{{ number_format($stats['total_credits']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <div class="text-muted small text-uppercase mb-1">Characters</div>
                <div class="h4 mb-0 fw-bold" style="color:#6c5ce7">{{ number_format($stats['total_characters']) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted">Trạng thái</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="all" {{ request('status','all')==='all'?'selected':'' }}>Tất cả</option>
                    <option value="processing" {{ request('status')==='processing'?'selected':'' }}>Đang xử lý</option>
                    <option value="completed" {{ request('status')==='completed'?'selected':'' }}>Hoàn thành</option>
                    <option value="failed" {{ request('status')==='failed'?'selected':'' }}>Thất bại</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Tìm user</label>
                <input type="text" name="user_search" class="form-control form-control-sm" placeholder="Tên hoặc email..." value="{{ request('user_search') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Ngôn ngữ đích</label>
                <select name="language" class="form-select form-select-sm">
                    <option value="">Tất cả</option>
                    @foreach($languages as $lang)
                    <option value="{{ $lang }}" {{ request('language')===$lang?'selected':'' }}>{{ $lang }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Sắp xếp</label>
                <select name="sort" class="form-select form-select-sm">
                    <option value="created_at" {{ request('sort','created_at')==='created_at'?'selected':'' }}>Ngày tạo</option>
                    <option value="credits_deducted" {{ request('sort')==='credits_deducted'?'selected':'' }}>Credits</option>
                    <option value="characters_used" {{ request('sort')==='characters_used'?'selected':'' }}>Characters</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fas fa-search"></i> Lọc</button>
                <a href="{{ route('admin.videodub.index') }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-redo"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-language"></i> Video Dub Jobs ({{ $jobs->total() }})</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Languages</th>
                        <th>Voice</th>
                        <th>Status</th>
                        <th>Stage</th>
                        <th>Characters</th>
                        <th>Credits</th>
                        <th>Duration</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($jobs as $job)
                    <tr>
                        <td><code>{{ $job->id }}</code></td>
                        <td>
                            @if($job->user)
                            <span class="badge bg-info">{{ $job->user->name }}</span>
                            @else
                            <span class="badge bg-secondary">Deleted</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-secondary">{{ $job->source_language ?? '?' }}</span>
                            <i class="fas fa-arrow-right text-muted mx-1" style="font-size:10px"></i>
                            <span class="badge bg-primary">{{ $job->target_language }}</span>
                        </td>
                        <td>
                            <small class="text-muted">{{ Str::limit($job->voice_id, 20) }}</small>
                            <br><span class="badge bg-light text-dark" style="font-size:10px">{{ $job->provider }}</span>
                        </td>
                        <td>
                            @php
                            $statusColors = ['completed' => 'success', 'failed' => 'danger', 'processing' => 'warning', 'tts_pending' => 'info'];
                            $color = $statusColors[$job->status] ?? 'secondary';
                            @endphp
                            <span class="badge bg-{{ $color }}">{{ $job->status }}</span>
                        </td>
                        <td><small>{{ $job->stage }}</small></td>
                        <td>{{ number_format($job->characters_used) }}</td>
                        <td>
                            @if($job->credits_deducted > 0)
                            <span class="badge bg-warning text-dark">{{ $job->credits_deducted }}</span>
                            @else
                            <span class="text-muted">0</span>
                            @endif
                        </td>
                        <td>
                            @if($job->duration_seconds)
                            {{ gmdate('H:i:s', $job->duration_seconds) }}
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td><small>{{ $job->created_at->format('d/m/Y H:i') }}</small></td>
                        <td>
                            <a href="{{ route('admin.videodub.show', $job->id) }}" class="btn btn-sm btn-outline-primary" title="Chi tiết">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="11" class="text-center py-5">
                            <i class="fas fa-language text-muted" style="font-size:3rem"></i>
                            <p class="text-muted mt-2">Chưa có video dub job nào</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($jobs->hasPages())
    <div class="card-footer">
        {{ $jobs->links() }}
    </div>
    @endif
</div>
@endsection
