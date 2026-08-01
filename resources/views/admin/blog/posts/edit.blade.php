@extends('admin.layout')
@section('title', 'Sửa bài viết')
@section('page-title', 'Sửa bài viết #' . $post->id)
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Posts', 'url' => route('admin.blog.posts.index')], ['label' => 'Sửa']]])
@endsection
@section('content')
<div class="card border-0 shadow-sm"><div class="card-body">
    <form method="POST" action="{{ route('admin.blog.posts.update', $post->id) }}">
        @csrf @method('PUT')
        @include('admin.blog.posts._form', ['post' => $post, 'categories' => $categories])
        <button type="submit" class="btn btn-primary">Lưu</button>
    </form>
</div></div>
@endsection
