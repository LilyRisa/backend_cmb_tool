{{-- Stats Card Partial
     Usage: @include('admin._partials._stats-card', [
         'icon' => 'fas fa-users',
         'value' => $totalUsers,
         'label' => 'Total Users',
         'variant' => 'primary',
         'subtitle' => '+5 hôm nay',  // optional
         'link' => route('admin.users.index'), // optional
     ]) --}}

@php
$variant = $variant ?? 'primary';
$link = $link ?? null;
$subtitle = $subtitle ?? null;
@endphp

<div class="col">
    @if($link)
    <a href="{{ $link }}" class="text-decoration-none">
        @endif
        <div class="stats-card {{ $variant }}">
            <div class="stats-icon">
                <i class="{{ $icon }}"></i>
            </div>
            <h3>{{ is_numeric($value) ? number_format($value) : $value }}</h3>
            <p>{{ $label }}</p>
            @if($subtitle)
            <div class="stats-subtitle">{{ $subtitle }}</div>
            @endif
        </div>
        @if($link)
    </a>
    @endif
</div>