@php($tool = $tool ?? null)

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $tool->name ?? '') }}">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Slug</label>
        <input type="text" name="slug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug', $tool->slug ?? '') }}">
        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">Type</label>
        <input type="text" name="type" class="form-control @error('type') is-invalid @enderror" value="{{ old('type', $tool->type ?? 'cmb_core') }}">
        @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">Version</label>
        <input type="text" name="version" class="form-control @error('version') is-invalid @enderror" value="{{ old('version', $tool->version ?? '') }}">
        @error('version')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">File size</label>
        <input type="text" name="file_size" class="form-control" value="{{ old('file_size', $tool->file_size ?? '') }}">
    </div>
    <div class="col-12 mb-3">
        <label class="form-label">Download URL</label>
        <input type="text" name="download_url" class="form-control @error('download_url') is-invalid @enderror" value="{{ old('download_url', $tool->download_url ?? '') }}">
        @error('download_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">SHA256</label>
        <input type="text" name="sha256" class="form-control" value="{{ old('sha256', $tool->sha256 ?? '') }}">
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Released at</label>
        <input type="date" name="released_at" class="form-control" value="{{ old('released_at', $tool->released_at?->format('Y-m-d') ?? '') }}">
    </div>
    <div class="col-12 mb-3">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2">{{ old('description', $tool->description ?? '') }}</textarea>
    </div>
    <div class="col-12 mb-3">
        <label class="form-label">Changelog</label>
        <textarea name="changelog" class="form-control" rows="3">{{ old('changelog', $tool->changelog ?? '') }}</textarea>
    </div>
    <div class="col-md-6 mb-3">
        <div class="form-check">
            <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active" {{ old('is_active', $tool->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="form-check">
            <input type="checkbox" name="is_latest" value="1" class="form-check-input" id="is_latest" {{ old('is_latest', $tool->is_latest ?? false) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_latest">Latest (bỏ đánh dấu latest của các bản khác cùng type)</label>
        </div>
    </div>
</div>
