@extends('admin.layout')
@section('title', 'Bug Reports')
@section('page-title', 'Báo cáo lỗi')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Bug Reports']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>User</th><th>Description</th><th>App Version</th><th>Screenshots</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
        @forelse($reports as $r)
            <tr>
                <td>{{ $r->id }}</td>
                <td>{{ $r->user->name ?? 'Deleted' }}</td>
                <td><small title="{{ $r->description }}">{{ \Illuminate\Support\Str::limit($r->description, 60) }}</small></td>
                <td>{{ $r->app_version }}</td>
                <td>{{ $r->screenshot_count }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.bug-reports.update', $r->id) }}">
                        @csrf @method('PUT')
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="pending" {{ $r->status==='pending'?'selected':'' }}>Pending</option>
                            <option value="in_progress" {{ $r->status==='in_progress'?'selected':'' }}>In Progress</option>
                            <option value="resolved" {{ $r->status==='resolved'?'selected':'' }}>Resolved</option>
                            <option value="wont_fix" {{ $r->status==='wont_fix'?'selected':'' }}>Won't Fix</option>
                        </select>
                    </form>
                </td>
                <td>{{ $r->created_at->format('d/m/Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center py-4 text-muted">Chưa có báo cáo lỗi nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($reports->hasPages())<div class="card-footer">{{ $reports->links() }}</div>@endif</div>
@endsection
