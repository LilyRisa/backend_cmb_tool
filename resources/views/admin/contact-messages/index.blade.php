@extends('admin.layout')
@section('title', 'Contact Messages')
@section('page-title', 'Liên hệ từ khách hàng')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Contact Messages']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Subject</th><th>Message</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($messages as $m)
            <tr>
                <td>{{ $m->id }}</td><td>{{ $m->name }}</td><td>{{ $m->email }}</td><td>{{ $m->subject }}</td>
                <td><small title="{{ $m->message }}">{{ \Illuminate\Support\Str::limit($m->message, 60) }}</small></td>
                <td>
                    <form method="POST" action="{{ route('admin.contact-messages.update', $m->id) }}" class="d-flex gap-1">
                        @csrf @method('PUT')
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="new" {{ $m->status==='new'?'selected':'' }}>New</option>
                            <option value="in_progress" {{ $m->status==='in_progress'?'selected':'' }}>In Progress</option>
                            <option value="resolved" {{ $m->status==='resolved'?'selected':'' }}>Resolved</option>
                        </select>
                        <input type="hidden" name="admin_notes" value="{{ $m->admin_notes }}">
                    </form>
                </td>
                <td>{{ $m->created_at->format('d/m/Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center py-4 text-muted">Chưa có liên hệ nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($messages->hasPages())<div class="card-footer">{{ $messages->links() }}</div>@endif</div>
@endsection
