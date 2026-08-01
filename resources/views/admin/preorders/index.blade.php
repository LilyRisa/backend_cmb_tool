@extends('admin.layout')
@section('title', 'Preorders')
@section('page-title', 'Đăng ký Preorder')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Preorders']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>Fullname</th><th>Email</th><th>Phone</th><th>Version</th><th>Early Access</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($preorders as $p)
            <tr>
                <td>{{ $p->id }}</td><td>{{ $p->fullname }}</td><td>{{ $p->email }}</td><td>{{ $p->phone }}</td>
                <td>{{ $p->product_version }}</td><td>{{ $p->early_access ? 'Yes' : 'No' }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.preorders.update', $p->id) }}">
                        @csrf @method('PUT')
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="pending" {{ $p->status==='pending'?'selected':'' }}>Pending</option>
                            <option value="contacted" {{ $p->status==='contacted'?'selected':'' }}>Contacted</option>
                            <option value="converted" {{ $p->status==='converted'?'selected':'' }}>Converted</option>
                            <option value="cancelled" {{ $p->status==='cancelled'?'selected':'' }}>Cancelled</option>
                        </select>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center py-4 text-muted">Chưa có preorder nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($preorders->hasPages())<div class="card-footer">{{ $preorders->links() }}</div>@endif</div>
@endsection
