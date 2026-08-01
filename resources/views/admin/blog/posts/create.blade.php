@extends('admin.layout')
@section('title', 'Thêm bài viết')
@section('page-title', 'Thêm bài viết Blog')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Posts', 'url' => route('admin.blog.posts.index')], ['label' => 'Thêm mới']]])
@endsection
@section('content')
<div class="card border-0 shadow-sm"><div class="card-body">
    <form method="POST" action="{{ route('admin.blog.posts.store') }}">
        @csrf
        @include('admin.blog.posts._form', ['categories' => $categories])
        <button type="submit" class="btn btn-primary">Tạo</button>
    </form>
</div></div>
@endsection
