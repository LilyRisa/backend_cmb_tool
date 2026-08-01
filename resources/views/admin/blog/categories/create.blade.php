@extends('admin.layout')
@section('title', 'Thêm chuyên mục')
@section('page-title', 'Thêm chuyên mục blog')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Categories', 'url' => route('admin.blog.categories.index')], ['label' => 'Thêm mới']]])
@endsection
@section('content')
<div class="card border-0 shadow-sm"><div class="card-body">
    <form method="POST" action="{{ route('admin.blog.categories.store') }}">
        @csrf
        @include('admin.blog.categories._form')
        <button type="submit" class="btn btn-primary">Tạo</button>
    </form>
</div></div>
@endsection
