@extends('admin.layout')

@section('title', 'Tool Settings')
@section('page-title', 'Tool Settings')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Tool Settings']]])
@endsection

@section('content')
@if(session('success'))
<div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card border-0 shadow-sm">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-image"></i> Image Generation</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.tool-settings.update') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label">Base URL</label>
                <input type="text" name="image_gen_base_url" class="form-control @error('image_gen_base_url') is-invalid @enderror" value="{{ old('image_gen_base_url', $settings['image_gen_base_url']) }}">
                @error('image_gen_base_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Model</label>
                <input type="text" name="image_gen_model" class="form-control @error('image_gen_model') is-invalid @enderror" value="{{ old('image_gen_model', $settings['image_gen_model']) }}">
                @error('image_gen_model')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Credits per image</label>
                <input type="number" min="1" name="image_gen_credits_per_image" class="form-control @error('image_gen_credits_per_image') is-invalid @enderror" value="{{ old('image_gen_credits_per_image', $settings['image_gen_credits_per_image']) }}">
                @error('image_gen_credits_per_image')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">API Key</label>
                <input type="password" name="image_gen_api_key" class="form-control" placeholder="{{ $settings['image_gen_api_key_set'] ? '••••••••  (đã cấu hình — để trống nếu không đổi)' : 'Chưa cấu hình' }}">
                <small class="text-muted">Để trống nếu không muốn thay đổi API key hiện tại.</small>
            </div>

            <button type="submit" class="btn btn-primary">Lưu cấu hình</button>
        </form>
    </div>
</div>
@endsection
