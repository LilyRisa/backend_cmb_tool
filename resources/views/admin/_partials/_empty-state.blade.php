{{-- Empty State Partial
     Usage: @include('admin._partials._empty-state', [
         'icon' => 'fas fa-inbox',
         'message' => 'Chưa có dữ liệu',
         'actionText' => 'Tạo mới',       // optional
         'actionUrl' => '#',               // optional
         'actionId' => 'btnCreate',        // optional
     ]) --}}

@php
$icon = $icon ?? 'fas fa-inbox';
$message = $message ?? 'Chưa có dữ liệu nào.';
@endphp

<div class="empty-state">
    <i class="{{ $icon }}"></i>
    <p>{{ $message }}</p>
    @if(isset($actionText))
    @if(isset($actionUrl))
    <a href="{{ $actionUrl }}" class="btn btn-primary btn-sm" @if(isset($actionId)) id="{{ $actionId }}" @endif>
        <i class="fas fa-plus me-1"></i>{{ $actionText }}
    </a>
    @elseif(isset($actionId))
    <button class="btn btn-primary btn-sm" id="{{ $actionId }}">
        <i class="fas fa-plus me-1"></i>{{ $actionText }}
    </button>
    @endif
    @endif
</div>