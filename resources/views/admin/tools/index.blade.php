@extends('admin.layout')

@section('title', 'Tools')
@section('page-title', 'Quản lý Tool')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Tools']]])
@endsection

@section('content')
@if(session('success'))
<div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card mb-3 border-0 shadow-sm">
    <div class="card-body py-3 d-flex justify-content-between align-items-center">
        <form method="GET" class="d-flex gap-2">
            <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Tất cả loại</option>
                @foreach($types as $t)
                <option value="{{ $t }}" {{ request('type')===$t?'selected':'' }}>{{ $t }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('admin.tools.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Thêm phiên bản</a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Type</th><th>Version</th><th>Size</th><th>Active</th><th>Latest</th><th>Downloads</th><th>Released</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse($tools as $tool)
                    <tr>
                        <td><code>{{ $tool->id }}</code></td>
                        <td>{{ $tool->name }}</td>
                        <td><span class="badge bg-secondary">{{ $tool->type }}</span></td>
                        <td>{{ $tool->version }}</td>
                        <td>{{ $tool->file_size }}</td>
                        <td>{{ $tool->is_active ? 'Yes' : 'No' }}</td>
                        <td>{{ $tool->is_latest ? 'Yes' : 'No' }}</td>
                        <td>{{ number_format($tool->download_count) }}</td>
                        <td>{{ $tool->released_at?->format('d/m/Y') }}</td>
                        <td>
                            <a href="{{ route('admin.tools.edit', $tool->id) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                            <form method="POST" action="{{ route('admin.tools.destroy', $tool->id) }}" class="d-inline" onsubmit="return confirm('Xoá phiên bản này?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="text-center py-4 text-muted">Chưa có tool nào</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($tools->hasPages())
    <div class="card-footer">{{ $tools->links() }}</div>
    @endif
</div>
@endsection
