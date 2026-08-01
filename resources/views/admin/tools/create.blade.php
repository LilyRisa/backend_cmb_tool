@extends('admin.layout')

@section('title', 'Thêm Tool')
@section('page-title', 'Thêm phiên bản mới')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Tools', 'url' => route('admin.tools.index')], ['label' => 'Thêm mới']]])
@endsection

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.tools.store') }}">
            @csrf
            @include('admin.tools._form')
            <button type="submit" class="btn btn-primary">Tạo</button>
        </form>
    </div>
</div>
@endsection
