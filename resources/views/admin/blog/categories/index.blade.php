@extends('admin.layout')
@section('title', 'Blog Categories')
@section('page-title', 'Chuyên mục Blog')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Categories']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="mb-3"><a href="{{ route('admin.blog.categories.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Thêm chuyên mục</a></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>Name</th><th>Slug</th><th>Posts</th><th>Active</th><th></th></tr></thead>
        <tbody>
        @forelse($categories as $c)
            <tr>
                <td>{{ $c->id }}</td><td>{{ $c->name }}</td><td>{{ $c->slug }}</td><td>{{ $c->posts_count }}</td>
                <td>{{ $c->is_active ? 'Yes' : 'No' }}</td>
                <td>
                    <a href="{{ route('admin.blog.categories.edit', $c->id) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                    <form method="POST" action="{{ route('admin.blog.categories.destroy', $c->id) }}" class="d-inline" onsubmit="return confirm('Xoá?')">
                        @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center py-4 text-muted">Chưa có chuyên mục nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($categories->hasPages())<div class="card-footer">{{ $categories->links() }}</div>@endif</div>
@endsection
