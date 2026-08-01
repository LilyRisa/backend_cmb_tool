@extends('admin.layout')
@section('title', 'Blog Posts')
@section('page-title', 'Bài viết Blog')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Posts']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="mb-3"><a href="{{ route('admin.blog.posts.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Thêm bài viết</a></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Published</th><th>Views</th><th></th></tr></thead>
        <tbody>
        @forelse($posts as $p)
            <tr>
                <td>{{ $p->id }}</td><td>{{ $p->title }}</td><td>{{ $p->category->name ?? '—' }}</td>
                <td>{{ $p->is_published ? 'Yes' : 'No' }}</td><td>{{ number_format($p->views) }}</td>
                <td>
                    <a href="{{ route('admin.blog.posts.edit', $p->id) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                    <form method="POST" action="{{ route('admin.blog.posts.destroy', $p->id) }}" class="d-inline" onsubmit="return confirm('Xoá?')">
                        @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center py-4 text-muted">Chưa có bài viết nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($posts->hasPages())<div class="card-footer">{{ $posts->links() }}</div>@endif</div>
@endsection
