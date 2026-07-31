@extends('admin.layout')

@section('title', 'Video Dub Job #' . $job->id)
@section('page-title', 'Chi tiết Job #' . $job->id)

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [
['label' => 'Video Dub Jobs', 'url' => route('admin.videodub.index')],
['label' => 'Job #' . $job->id]
]])
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-language"></i> Job Information</h5>
                @php
                $statusColors = ['completed' => 'success', 'failed' => 'danger', 'processing' => 'warning', 'tts_pending' => 'info'];
                $color = $statusColors[$job->status] ?? 'secondary';
                @endphp
                <span class="badge bg-{{ $color }} fs-6">{{ strtoupper($job->status) }}</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <th class="text-muted" width="40%">User</th>
                                <td>
                                    @if($job->user)
                                    {{-- No link: admin.analytics.user isn't built in this project yet --}}
                                    <strong>{{ $job->user->name }}</strong>
                                    <br><small class="text-muted">{{ $job->user->email }}</small>
                                    @else
                                    <span class="text-muted">Deleted user</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Source Language</th>
                                <td><span class="badge bg-secondary">{{ $job->source_language ?? 'Auto-detect' }}</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Target Language</th>
                                <td><span class="badge bg-primary">{{ $job->target_language }}</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Stage</th>
                                <td><span class="badge bg-info">{{ $job->stage }}</span></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <th class="text-muted" width="40%">Voice</th>
                                <td><code>{{ $job->voice_id }}</code></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Provider</th>
                                <td><span class="badge bg-dark">{{ $job->provider }}</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Model</th>
                                <td>{{ $job->model_id ?? 'Default' }}</td>
                            </tr>
                            <tr>
                                <th class="text-muted">Created</th>
                                <td>{{ $job->created_at->format('d/m/Y H:i:s') }}</td>
                            </tr>
                            @if($job->updated_at && $job->updated_at != $job->created_at)
                            <tr>
                                <th class="text-muted">Updated</th>
                                <td>{{ $job->updated_at->format('d/m/Y H:i:s') }}</td>
                            </tr>
                            @endif
                        </table>
                    </div>
                </div>

                @if($job->error)
                <div class="alert alert-danger mt-3 mb-0">
                    <strong><i class="fas fa-exclamation-triangle"></i> Lỗi:</strong>
                    <pre class="mb-0 mt-1" style="white-space:pre-wrap;font-size:12px">{{ $job->error }}</pre>
                </div>
                @endif

                @if($job->voice_settings)
                <div class="mt-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-sliders-h"></i> Voice Settings</h6>
                    <pre class="bg-light p-2 rounded" style="font-size:11px;max-height:100px;overflow:auto">{{ json_encode($job->voice_settings, JSON_PRETTY_PRINT) }}</pre>
                </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-coins"></i> Credits Summary</h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Characters Used</span>
                    <strong>{{ number_format($job->characters_used) }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Credits Deducted</span>
                    <strong class="text-warning">{{ number_format($job->credits_deducted) }}</strong>
                </div>
                @if($job->duration_seconds)
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Duration</span>
                    <strong>{{ gmdate('H:i:s', $job->duration_seconds) }}</strong>
                </div>
                @endif
                <hr>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">TTS Tasks</span>
                    <strong>{{ $ttsStats['total'] }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted small">Completed</span>
                    <span class="badge bg-success">{{ $ttsStats['completed'] }}</span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted small">Failed</span>
                    <span class="badge bg-danger">{{ $ttsStats['failed'] }}</span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted small">Pending</span>
                    <span class="badge bg-warning">{{ $ttsStats['pending'] }}</span>
                </div>
            </div>
        </div>

        @if($job->audio_url)
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-volume-up"></i> Final Audio</h6>
            </div>
            <div class="card-body">
                <audio controls class="w-100" preload="metadata">
                    <source src="{{ $job->audio_url }}">
                </audio>
                <a href="{{ $job->audio_url }}" target="_blank" class="btn btn-sm btn-outline-primary mt-2 w-100">
                    <i class="fas fa-external-link-alt"></i> Open URL
                </a>
            </div>
        </div>
        @endif
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-file-alt"></i> SRT Original</h6>
            </div>
            <div class="card-body p-0">
                @if($job->srt_original)
                <pre class="p-3 mb-0" style="max-height:400px;overflow:auto;font-size:11px;background:#f8f9fa">{{ $job->srt_original }}</pre>
                @else
                <div class="text-center text-muted py-4">Không có SRT gốc</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-language"></i> SRT Translated</h6>
            </div>
            <div class="card-body p-0">
                @if($job->srt_translated)
                <pre class="p-3 mb-0" style="max-height:400px;overflow:auto;font-size:11px;background:#f8f9fa">{{ $job->srt_translated }}</pre>
                @else
                <div class="text-center text-muted py-4">Chưa có bản dịch</div>
                @endif
            </div>
        </div>
    </div>
</div>

@if($ttsHistories->isNotEmpty())
<div class="card border-0 shadow-sm">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-tasks"></i> Linked TTS Tasks ({{ $ttsHistories->count() }})</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ID</th>
                        <th>Text</th>
                        <th>Characters</th>
                        <th>Credits</th>
                        <th>Status</th>
                        <th>Audio</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ttsHistories as $idx => $tts)
                    <tr>
                        <td>{{ $idx + 1 }}</td>
                        <td><code>{{ $tts->id }}</code></td>
                        <td>
                            <small title="{{ $tts->text ?? '' }}">{{ Str::limit($tts->text ?? '', 60) }}</small>
                        </td>
                        <td>{{ number_format($tts->characters_used ?? 0) }}</td>
                        <td>
                            @if(($tts->credits_deducted_user ?? 0) > 0)
                            <span class="badge bg-warning text-dark">{{ $tts->credits_deducted_user }}</span>
                            @else
                            <span class="text-muted">0</span>
                            @endif
                        </td>
                        <td>
                            @php
                            $ttsColor = match($tts->status ?? 'unknown') {
                                'completed' => 'success',
                                'failed' => 'danger',
                                'processing' => 'warning',
                                default => 'secondary',
                            };
                            @endphp
                            <span class="badge bg-{{ $ttsColor }}">{{ $tts->status ?? 'N/A' }}</span>
                        </td>
                        <td>
                            @if($tts->audio_url)
                            <a href="{{ $tts->audio_url }}" target="_blank" class="btn btn-sm btn-outline-info" title="Play">
                                <i class="fas fa-play"></i>
                            </a>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td><small>{{ $tts->created_at ? $tts->created_at->format('H:i:s') : 'N/A' }}</small></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<div class="mt-3">
    <a href="{{ route('admin.videodub.index') }}" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Quay lại danh sách
    </a>
</div>
@endsection
