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

<form method="POST" action="{{ route('admin.tool-settings.update') }}">
    @csrf

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-file-lines"></i> AI Text (Script / Scene / Dịch)</h5>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Base URL</label>
                <input type="text" name="ai_text_base_url" class="form-control @error('ai_text_base_url') is-invalid @enderror" value="{{ old('ai_text_base_url', $settings['ai_text_base_url']) }}">
                <small class="text-muted">Endpoint tương thích OpenAI chat-completions, vd: https://openrouter.ai/api/v1</small>
                @error('ai_text_base_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Model</label>
                <input type="text" name="ai_text_model" class="form-control @error('ai_text_model') is-invalid @enderror" value="{{ old('ai_text_model', $settings['ai_text_model']) }}">
                @error('ai_text_model')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-0">
                <label class="form-label">API Key</label>
                <input type="text" name="ai_text_api_key" class="form-control" value="{{ old('ai_text_api_key', $settings['ai_text_api_key']) }}" placeholder="Chưa cấu hình">
                <small class="text-muted">Dùng cho tạo script, tạo scene, và dịch text/SRT.</small>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-image"></i> Image Generation</h5>
        </div>
        <div class="card-body">
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

            <div class="mb-0">
                <label class="form-label">API Key</label>
                <input type="text" name="image_gen_api_key" class="form-control" value="{{ old('image_gen_api_key', $settings['image_gen_api_key']) }}" placeholder="Chưa cấu hình">
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-microphone"></i> GenMax TTS</h5>
        </div>
        <div class="card-body">
            <div class="mb-0">
                <label class="form-label">API Key</label>
                <input type="text" name="genmax_api_key" class="form-control" value="{{ old('genmax_api_key', $settings['genmax_api_key']) }}" placeholder="Chưa cấu hình">
                <small class="text-muted">API key của nhà cung cấp GenMax (dùng cho lồng tiếng AI / nhân bản giọng nói).</small>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Lưu cấu hình</button>
</form>
@endsection
