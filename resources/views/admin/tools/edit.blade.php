@extends('admin.layout')

@section('title', 'Sửa Tool')
@section('page-title', 'Sửa phiên bản #' . $tool->id)

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Tools', 'url' => route('admin.tools.index')], ['label' => 'Sửa']]])
@endsection

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.tools.update', $tool->id) }}">
            @csrf @method('PUT')
            @include('admin.tools._form', ['tool' => $tool])
            <button type="submit" class="btn btn-primary">Lưu</button>
        </form>
    </div>
</div>
@endsection
