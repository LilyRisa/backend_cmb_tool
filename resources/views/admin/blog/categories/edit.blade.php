@extends('admin.layout')
@section('title', 'Sửa chuyên mục')
@section('page-title', 'Sửa chuyên mục #' . $category->id)
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Categories', 'url' => route('admin.blog.categories.index')], ['label' => 'Sửa']]])
@endsection
@section('content')
<div class="card border-0 shadow-sm"><div class="card-body">
    <form method="POST" action="{{ route('admin.blog.categories.update', $category->id) }}">
        @csrf @method('PUT')
        @include('admin.blog.categories._form', ['category' => $category])
        <button type="submit" class="btn btn-primary">Lưu</button>
    </form>
</div></div>
@endsection
