<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — CMB Core Tool</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <!-- Admin Custom CSS -->
    <link rel="stylesheet" href="{{ asset('css/admin-custom.css') }}">

    @stack('styles')
</head>
<body>

    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay"></div>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-icon">
                <i class="fas fa-bolt"></i>
            </div>
            <span class="logo-text">CMB Admin</span>
        </div>

        <nav class="sidebar-nav">
            {{-- Tổng Quan --}}
            <div class="sidebar-group" data-group="overview">
                <div class="sidebar-group-title">
                    <span>📊 Tổng Quan</span>
                    <i class="fas fa-chevron-down group-chevron"></i>
                </div>
                <ul class="sidebar-group-items">
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"
                            href="{{ route('admin.dashboard') }}">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                </ul>
            </div>

            {{-- Người Dùng --}}
            <div class="sidebar-group" data-group="users">
                <div class="sidebar-group-title">
                    <span>👥 Người Dùng</span>
                    <i class="fas fa-chevron-down group-chevron"></i>
                </div>
                <ul class="sidebar-group-items">
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"
                            href="{{ route('admin.users.index') }}">
                            <i class="fas fa-users"></i> Users
                        </a>
                    </li>
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.analytics.ip*') ? 'active' : '' }}"
                            href="{{ route('admin.analytics.ip') }}">
                            <i class="fas fa-chart-line"></i> IP Fraud Detection
                        </a>
                    </li>
                </ul>
            </div>

            {{-- Thanh Toán --}}
            <div class="sidebar-group" data-group="payment">
                <div class="sidebar-group-title">
                    <span>💳 Thanh Toán</span>
                    <i class="fas fa-chevron-down group-chevron"></i>
                </div>
                <ul class="sidebar-group-items">
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.analytics.topups') ? 'active' : '' }}"
                            href="{{ route('admin.analytics.topups') }}">
                            <i class="fas fa-coins"></i> Credit Top-up
                        </a>
                    </li>
                </ul>
            </div>

            {{-- Nội Dung --}}
            <div class="sidebar-group" data-group="content">
                <div class="sidebar-group-title">
                    <span>📝 Nội Dung</span>
                    <i class="fas fa-chevron-down group-chevron"></i>
                </div>
                <ul class="sidebar-group-items">
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.blog.*') ? 'active' : '' }}"
                            href="{{ route('admin.blog.posts.index') }}">
                            <i class="fas fa-newspaper"></i> Blog
                        </a>
                    </li>
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.preorders.*') ? 'active' : '' }}"
                            href="{{ route('admin.preorders.index') }}">
                            <i class="fas fa-shopping-cart"></i> Đơn Đặt Hàng
                        </a>
                    </li>
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.contact-messages.*') ? 'active' : '' }}"
                            href="{{ route('admin.contact-messages.index') }}">
                            <i class="fas fa-envelope"></i> Tin Nhắn Liên Hệ
                        </a>
                    </li>
                </ul>
            </div>

            {{-- Phần Mềm --}}
            <div class="sidebar-group" data-group="software">
                <div class="sidebar-group-title">
                    <span>🔧 Phần Mềm</span>
                    <i class="fas fa-chevron-down group-chevron"></i>
                </div>
                <ul class="sidebar-group-items">
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.tools.*') ? 'active' : '' }}"
                            href="{{ route('admin.tools.index') }}">
                            <i class="fas fa-download"></i> Công Cụ & Firmware
                        </a>
                    </li>
                </ul>
            </div>

            {{-- Tool TTS --}}
            <div class="sidebar-group" data-group="tts">
                <div class="sidebar-group-title">
                    <span>🎙️ Tool TTS</span>
                    <i class="fas fa-chevron-down group-chevron"></i>
                </div>
                <ul class="sidebar-group-items">
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.tool-settings.*') ? 'active' : '' }}"
                            href="{{ route('admin.tool-settings.index') }}">
                            <i class="fas fa-key"></i> API Key Settings
                        </a>
                    </li>
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.tool-stats.*') ? 'active' : '' }}"
                            href="{{ route('admin.tool-stats.index') }}">
                            <i class="fas fa-chart-bar"></i> Tool Statistics
                        </a>
                    </li>
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.videodub.*') ? 'active' : '' }}"
                            href="{{ route('admin.videodub.index') }}">
                            <i class="fas fa-language"></i> Video Dub Jobs
                        </a>
                    </li>
                </ul>
            </div>

            {{-- Hỗ Trợ --}}
            <div class="sidebar-group" data-group="support">
                <div class="sidebar-group-title">
                    <span>🐛 Hỗ Trợ</span>
                    <i class="fas fa-chevron-down group-chevron"></i>
                </div>
                <ul class="sidebar-group-items">
                    <li>
                        <a class="nav-link {{ request()->routeIs('admin.bug-reports.*') ? 'active' : '' }}"
                            href="{{ route('admin.bug-reports.index') }}">
                            <i class="fas fa-bug"></i> Báo Lỗi
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="d-flex align-items-center">
                <button class="sidebar-toggle" type="button" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>

                @hasSection('breadcrumb')
                @yield('breadcrumb')
                @else
                @include('admin._partials._breadcrumb', [
                'items' => [
                ['label' => View::yieldContent('page-title', 'Dashboard')]
                ]
                ])
                @endif
            </div>

            <div class="d-flex align-items-center gap-3">
                <span class="text-muted" style="font-size: 13px;">
                    <i class="fas fa-user-shield me-1"></i>{{ auth()->user()->name }}
                </span>
                <form method="POST" action="{{ route('admin.logout') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-sign-out-alt me-1"></i>Đăng xuất
                    </button>
                </form>
            </div>
        </div>

        <!-- Flash Messages -->
        @if(session('success'))
        <div class="content-wrapper" style="padding-bottom:0;">
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-1"></i>{{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
        @endif
        @if(session('error'))
        <div class="content-wrapper" style="padding-bottom:0;">
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-1"></i>{{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
        @endif

        <!-- Content -->
        <div class="content-wrapper">
            @yield('content')
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Admin Core JS -->
    <script src="{{ asset('js/admin-core.js') }}"></script>

    @stack('scripts')
</body>
</html>
