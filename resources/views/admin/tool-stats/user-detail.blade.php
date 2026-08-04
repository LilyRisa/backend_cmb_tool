@extends('admin.layout')

@section('title', 'User Detail - ' . $user->name)
@section('page-title', 'Chi tiết User: ' . $user->name)

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [
    ['label' => 'Tool Statistics', 'url' => route('admin.tool-stats.index')],
    ['label' => $user->name],
]])
@endsection

@section('content')

{{-- User Info --}}
<div class="row mb-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-user me-2"></i>Thông tin User
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Name</td>
                        <td><strong>{{ $user->name }}</strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Email</td>
                        <td>{{ $user->email }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Package</td>
                        <td>
                            @if($user->package_type === 'free')
                            <span class="badge bg-secondary">Free</span>
                            @elseif($user->isPremium())
                            <span class="badge bg-success">{{ ucfirst($user->package_type) }}</span>
                            @else
                            <span class="badge bg-secondary" title="package_type vẫn là {{ $user->package_type }} nhưng đã hết hạn (package_expires_at đã qua)">{{ ucfirst($user->package_type) }} (Hết hạn)</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Hết hạn gói lúc</td>
                        <td><small>{{ $user->package_expires_at ? \Carbon\Carbon::parse($user->package_expires_at)->format('d/m/Y H:i') : 'Không giới hạn' }}</small></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Credits (Tổng)</td>
                        <td><strong>{{ number_format(($user->monthly_credits ?? 0) + ($user->purchased_credits ?? 0)) }}</strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Monthly Credits</td>
                        <td><span class="badge bg-info">{{ number_format($user->monthly_credits ?? 0) }}</span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Purchased Credits</td>
                        <td><span class="badge bg-success">{{ number_format($user->purchased_credits ?? 0) }}</span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Reset lần cuối</td>
                        <td><small>{{ $user->credits_reset_at ? \Carbon\Carbon::parse($user->credits_reset_at)->format('d/m/Y H:i') : '—' }}</small></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Credits đã dùng</td>
                        <td>{{ number_format($totalUsed) }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">TTS Requests</td>
                        <td>{{ $totalTts }} ({{ $completedTts }} hoàn thành)</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Đăng ký</td>
                        <td>{{ $user->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Quick Actions --}}
        <div class="card">
            <div class="card-header">
                <i class="fas fa-bolt me-2"></i>Thao tác nhanh
            </div>
            <div class="card-body">
                {{-- Add Credits --}}
                <form method="POST" action="{{ route('admin.tool-stats.add-credits', $user->id) }}" class="mb-3">
                    @csrf
                    <label class="form-label fw-bold">Cộng Credits</label>
                    <div class="input-group mb-2">
                        <input type="number" name="amount" class="form-control" placeholder="Số credits" min="1" required>
                        <select name="credit_type" class="form-select" style="max-width: 140px;">
                            <option value="purchased">Purchased</option>
                            <option value="monthly">Monthly</option>
                        </select>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-plus"></i> Cộng
                        </button>
                    </div>
                    <input type="hidden" name="description" value="Admin cộng credits">
                </form>

                {{-- Set Premium --}}
                <form method="POST" action="{{ route('admin.tool-stats.set-premium', $user->id) }}">
                    @csrf
                    <label class="form-label fw-bold">Gói Premium</label>
                    <div class="row g-2">
                        <div class="col-6">
                            <select name="package_type" class="form-select form-select-sm">
                                <option value="free" {{ $user->package_type === 'free' ? 'selected' : '' }}>Free</option>
                                <option value="premium" {{ $user->package_type === 'premium' ? 'selected' : '' }}>Premium</option>
                                <option value="enterprise" {{ $user->package_type === 'enterprise' ? 'selected' : '' }}>Enterprise</option>
                            </select>
                        </div>
                        <div class="col-3">
                            <input type="number" name="duration_days" class="form-control form-control-sm" placeholder="Ngày" value="30" min="1">
                        </div>
                        <div class="col-3">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="fas fa-save"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Login Logs --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-sign-in-alt me-2"></i>Login Logs (50 gần nhất)
            </div>
            <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead style="position: sticky; top: 0; background: #f8f9fa;">
                        <tr>
                            <th>Action</th>
                            <th>IP Address</th>
                            <th>Source</th>
                            <th>User Agent</th>
                            <th>Thời gian</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loginLogs as $log)
                        <tr>
                            <td>
                                <span class="badge bg-{{ $log->action === 'login' ? 'info' : 'success' }}">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td><code>{{ $log->ip_address }}</code></td>
                            <td>{{ $log->source }}</td>
                            <td><small class="text-muted" title="{{ $log->user_agent }}">{{ Str::limit($log->user_agent, 40) }}</small></td>
                            <td><small>{{ $log->created_at->format('d/m/Y H:i') }}</small></td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">Chưa có log</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Credit Transactions --}}
<div class="card">
    <div class="card-header">
        <i class="fas fa-exchange-alt me-2"></i>Lịch sử giao dịch Credits
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Loại</th>
                    <th>Số lượng</th>
                    <th>Số dư sau</th>
                    <th>Mô tả</th>
                    <th>Thời gian</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $t)
                <tr>
                    <td>{{ $t->id }}</td>
                    <td>
                        @php
                        $typeColors = ['deduct' => 'danger', 'topup' => 'success', 'bonus' => 'primary', 'refund' => 'warning'];
                        @endphp
                        <span class="badge bg-{{ $typeColors[$t->type] ?? 'secondary' }}">{{ $t->type }}</span>
                    </td>
                    <td class="{{ $t->amount >= 0 ? 'text-success' : 'text-danger' }}">
                        <strong>{{ $t->amount >= 0 ? '+' : '' }}{{ number_format($t->amount) }}</strong>
                    </td>
                    <td>{{ number_format($t->balance_after) }}</td>
                    <td><small>{{ $t->description }}</small></td>
                    <td><small>{{ $t->created_at->format('d/m/Y H:i') }}</small></td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">Chưa có giao dịch</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transactions->hasPages())
    <div class="card-footer">
        {{ $transactions->links() }}
    </div>
    @endif
</div>

{{-- TTS History --}}
<div class="card mt-4">
    <div class="card-header">
        <i class="fas fa-history me-2"></i>Lịch sử TTS
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Provider</th>
                    <th>Text</th>
                    <th>Status</th>
                    <th>Credits (User)</th>
                    <th>Credits (Provider)</th>
                    <th>Thời gian</th>
                </tr>
            </thead>
            <tbody>
                @forelse($ttsHistories as $h)
                <tr>
                    <td>{{ $h->id }}</td>
                    <td>
                        <span class="badge bg-{{ $h->provider === 'elevenlabs' ? 'primary' : 'info' }}">
                            {{ $h->provider }}
                        </span>
                    </td>
                    <td title="{{ $h->text }}"><small>{{ Str::limit($h->text, 50) }}</small></td>
                    <td>
                        @php
                        $statusColors = ['pending' => 'warning', 'processing' => 'info', 'completed' => 'success', 'failed' => 'danger'];
                        @endphp
                        <span class="badge bg-{{ $statusColors[$h->status] ?? 'secondary' }}">{{ $h->status }}</span>
                    </td>
                    <td>{{ number_format($h->credits_deducted_user) }}</td>
                    <td>{{ number_format($h->credits_deducted_provider) }}</td>
                    <td><small>{{ $h->created_at->format('d/m/Y H:i') }}</small></td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">Chưa có lịch sử</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($ttsHistories->hasPages())
    <div class="card-footer">
        {{ $ttsHistories->appends(['page' => request('page')])->links() }}
    </div>
    @endif
</div>

{{-- Video Dub History --}}
<div class="card mt-4">
    <div class="card-header">
        <i class="fas fa-language me-2"></i>Lịch sử Video Dub
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Thời gian</th>
                    <th>Ngôn ngữ</th>
                    <th>Status</th>
                    <th>Stage</th>
                    <th class="text-end">Credits</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody>
                @forelse($videoDubJobs as $job)
                <tr>
                    <td><small>{{ $job->created_at->format('d/m/Y H:i') }}</small></td>
                    <td><small>{{ $job->source_language ?? '—' }} → {{ $job->target_language ?? '—' }}</small></td>
                    <td>
                        @if($job->status === 'completed')
                        <span class="badge bg-success">Completed</span>
                        @elseif($job->status === 'processing')
                        <span class="badge bg-warning text-dark">Processing</span>
                        @elseif($job->status === 'failed')
                        <span class="badge bg-danger">Failed</span>
                        @else
                        <span class="badge bg-secondary">{{ $job->status }}</span>
                        @endif
                    </td>
                    <td><small>{{ $job->stage ?? '—' }}</small></td>
                    <td class="text-end">
                        <span class="badge bg-warning text-dark">{{ number_format($job->credits_deducted ?? 0) }}</span>
                    </td>
                    <td><small>{{ $job->duration_seconds ? gmdate('H:i:s', $job->duration_seconds) : '—' }}</small></td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-3">Không có Video Dub jobs</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($videoDubJobs->hasPages())
    <div class="card-footer">
        {{ $videoDubJobs->appends(['page' => request('page')])->links() }}
    </div>
    @endif
</div>

{{-- Bug Reports --}}
<div class="card mt-4">
    <div class="card-header">
        <i class="fas fa-bug me-2"></i>Báo lỗi đã gửi
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Thời gian</th>
                    <th>Mô tả lỗi</th>
                    <th>App Version</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bugReports as $report)
                <tr>
                    <td><small>{{ $report->created_at->format('d/m/Y H:i') }}</small></td>
                    <td style="max-width:300px;">
                        <small class="text-truncate d-inline-block" style="max-width:280px;" title="{{ $report->description }}">{{ $report->description }}</small>
                    </td>
                    <td><small>{{ $report->app_version ?? '—' }}</small></td>
                    <td>
                        @if($report->status === 'pending')
                        <span class="badge bg-warning text-dark">Pending</span>
                        @elseif($report->status === 'reviewed')
                        <span class="badge bg-info">Reviewed</span>
                        @elseif($report->status === 'resolved')
                        <span class="badge bg-success">Resolved</span>
                        @else
                        <span class="badge bg-secondary">{{ $report->status }}</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="text-center text-muted py-3">Không có báo lỗi</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($bugReports->hasPages())
    <div class="card-footer">
        {{ $bugReports->appends(['page' => request('page')])->links() }}
    </div>
    @endif
</div>

{{-- Feature Usage --}}
<div class="card mt-4">
    <div class="card-header">
        <i class="fas fa-chart-pie me-2"></i>Tần suất sử dụng tính năng
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th class="text-end">Lượt dùng</th>
                    <th>Lần cuối</th>
                </tr>
            </thead>
            <tbody>
                @forelse($featureUsages as $usage)
                <tr>
                    <td style="font-weight:500;">{{ $usage->feature_name }}</td>
                    <td class="text-end">
                        <span class="badge bg-primary">{{ number_format($usage->usage_count) }}</span>
                    </td>
                    <td>
                        <small class="text-muted">{{ $usage->last_used_at ? $usage->last_used_at->diffForHumans() : 'N/A' }}</small>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" class="text-center text-muted py-4">Chưa sử dụng tính năng nào</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    <a href="{{ route('admin.tool-stats.index') }}" class="btn btn-secondary">
        <i class="fas fa-arrow-left me-2"></i>Quay lại
    </a>
</div>

@endsection
