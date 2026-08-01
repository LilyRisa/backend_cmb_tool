# Phase 4A: Content/Intake Models + Admin CRUD + Desktop-App API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the remaining content/intake models from the original design spec (`Tool`, `BlogPost`, `BlogCategory`, `ContactMessage`, `Preorder`, `BugReport`, `FeatureUsage`), their admin management pages, and the desktop-app-facing API endpoints that depend on them (version/update-check, bug-report submission, feature-usage tracking).

**Architecture:** Seven independent Eloquent models with migrations whose schemas are taken verbatim from the real, already-running production database (verified column-by-column, not guessed). Admin pages reuse the `admin.layout` Bootstrap shell (Phase 3C) and the list/filter/paginate/detail pattern established by `VideoDubManagementController` (Phase 3C) and `ToolSettingsController` (Phase 3D). Desktop-app API endpoints live under a new `/api/cmb` prefix, matching the original design spec's route table.

**Tech Stack:** Laravel 10 (from Phases 1-3D), Blade + `admin.layout`, `Storage::disk('public')` for bug-report screenshot uploads (matching `OpenAiImageService`'s existing local-storage pattern from Phase 3D — no external CDN).

## Global Constraints

- Every new migration's column set must match the verified real production schema exactly (see each task's **Interfaces** block for the exact column list) — do not add or omit columns beyond what's specified.
- Admin routes go inside the existing `Route::prefix('admin')->name('admin.')->group(...)` block in `routes/web.php`, inside its `Route::middleware('admin')->group(...)` sub-group (Phase 1's `IsAdmin` middleware — default `web` guard, `is_admin` boolean, established test pattern: `User::factory()->create(['is_admin' => true])` + `$this->actingAs($admin)`, guest → redirect, authenticated non-admin → 403).
- The `Tool` update-check API (Task 3) goes under a new top-level `Route::prefix('cmb')` group in `routes/api.php`, matching both the original design spec's route table (`/api/cmb/*`) and the source controller's own `getCmb*` method naming. It has no auth (the desktop app calls it before login is possible).
- The `BugReport` (Task 7) and `FeatureUsage` (Task 8) APIs are simple authenticated utility endpoints — not specifically "cmb-branded" in the original spec and not credit/premium-gated — so they go in the existing top-level `Route::middleware(['auth:sanctum', 'token.version'])->group(...)` block in `routes/api.php` that already holds `/me`, `/logout`, `/account/*`, NOT inside `tool` (no premium gate applies) and NOT inside `cmb` (that prefix is Tool-specific per the point above).
- Every inline `throttle:` middleware needs an explicit unique 3rd segment, per this project's established convention (recurring bug class — verify no collision against the ~18 throttle keys already registered across `routes/api.php`/`routes/web.php`).
- Vietnamese user-facing error strings, matching every other controller in this project.
- `FeatureUsage` gets a model + migration + API endpoint only — no dedicated admin page (YAGNI: it's a pure analytics counter with no operational status to manage; the existing admin dashboard/stats pages can query it directly later if a reporting view is ever needed).
- `ContactMessage` and `Preorder` get model + migration + admin list/status-update views only — no public submission form yet (that's a separate, later sub-project covering the public portal pages).
- `BugReport` screenshots upload to `storage/app/public/bug-reports/` (this app's own storage), not an external CDN — the real production data's `static.cmbaudio.com` URLs are a source-system detail that does not carry over.

---

### Task 1: `Tool` model, migration, factory, and real-data seed

**Files:**
- Create: `database/migrations/2026_08_01_000001_create_tools_table.php`
- Create: `app/Models/Tool.php`
- Create: `database/factories/ToolFactory.php`
- Create: `database/seeders/ToolSeeder.php`
- Test: `tests/Unit/ToolTest.php`

**Interfaces:**
- Produces: `Tool` model with fillable columns `name, slug, type, version, description, download_url, file_size, sha256, changelog, is_active, is_latest, download_count, released_at`; casts `is_active`/`is_latest` to `boolean`, `download_count` to `integer`, `released_at` to `datetime`. Consumed by Task 2 (admin CRUD) and Task 3 (desktop-app API).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ToolTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_tool_with_all_fields(): void
    {
        $tool = Tool::create([
            'name' => 'CMB Core Marketing',
            'slug' => 'cmb-core-marketing-421',
            'type' => 'cmb_core',
            'version' => '4.2.1',
            'description' => 'Desktop automation tool',
            'download_url' => 'https://cdn.cmbcore.com/cmb-core-marketing/CMBcoreMKT%20Setup%204.2.1.exe',
            'file_size' => '202 MB',
            'sha256' => '17C8248621BE5C34CC7FE2BA3F49F404AA98DFF79447BBC374CC97A01FE33A40',
            'changelog' => 'Fix login and facebook processing bugs',
            'is_active' => true,
            'is_latest' => true,
            'download_count' => 0,
            'released_at' => '2026-07-05',
        ]);

        $this->assertTrue($tool->is_active);
        $this->assertTrue($tool->is_latest);
        $this->assertIsInt($tool->download_count);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $tool->released_at);
    }

    public function test_active_scope_filters_correctly(): void
    {
        Tool::factory()->create(['is_active' => true]);
        Tool::factory()->create(['is_active' => false]);

        $this->assertCount(1, Tool::where('is_active', true)->get());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ToolTest`
Expected: FAIL (`Class "App\Models\Tool" not found`)

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_01_000001_create_tools_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // 'firmware'/'flash_tool' exist in the source system (ESP32-specific,
            // out of scope) — this project only ever populates 'cmb_core', but the
            // column stays free-text for future extensibility rather than an enum.
            $table->string('type');
            $table->string('version');
            $table->text('description')->nullable();
            $table->text('download_url');
            // Free-text, not a strict byte count — the real source data mixes raw
            // byte strings ("2784") and pre-formatted strings ("202 MB").
            $table->string('file_size')->nullable();
            $table->string('sha256')->nullable();
            $table->longText('changelog')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_latest')->default(false);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index(['type', 'is_latest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tools');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/Tool.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tool extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'type', 'version', 'description', 'download_url',
        'file_size', 'sha256', 'changelog', 'is_active', 'is_latest',
        'download_count', 'released_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_latest' => 'boolean',
        'download_count' => 'integer',
        'released_at' => 'datetime',
    ];
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/ToolFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Tool;
use Illuminate\Database\Eloquent\Factories\Factory;

class ToolFactory extends Factory
{
    protected $model = Tool::class;

    public function definition(): array
    {
        $version = $this->faker->numerify('#.#.#');

        return [
            'name' => 'CMB Core Marketing',
            'slug' => 'cmb-core-marketing-' . str_replace('.', '', $version),
            'type' => 'cmb_core',
            'version' => $version,
            'description' => $this->faker->sentence(),
            'download_url' => "https://cdn.cmbcore.com/cmb-core-marketing/CMBcoreMKT%20Setup%20{$version}.exe",
            'file_size' => '200 MB',
            'sha256' => strtoupper($this->faker->sha256()),
            'changelog' => $this->faker->sentence(),
            'is_active' => true,
            'is_latest' => false,
            'download_count' => 0,
            'released_at' => now(),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ToolTest`
Expected: PASS (2 tests)

- [ ] **Step 7: Write the real-data seeder**

Create `database/seeders/ToolSeeder.php` — the 8 real `cmb_core` releases, verified against the live production `tools` table (id/timestamps intentionally not preserved here, unlike the earlier `import:marketing-data` command, since these specific 8 rows have no foreign keys pointing at them from any other table — a fresh seed is simpler and equally correct):

```php
<?php

namespace Database\Seeders;

use App\Models\Tool;
use Illuminate\Database\Seeder;

class ToolSeeder extends Seeder
{
    public function run(): void
    {
        if (Tool::where('type', 'cmb_core')->exists()) {
            $this->command->warn('cmb_core tools already seeded, skipping.');
            return;
        }

        $releases = [
            ['version' => '4.1.4', 'changelog' => 'Cập nhật tính năng auto comment facebook và sửa lỗi, tối ưu', 'file_size' => '193.84 MB', 'released_at' => '2026-03-11', 'download_count' => 0],
            ['version' => '4.1.5', 'changelog' => 'Fix bug', 'file_size' => '193.9 MB', 'released_at' => '2026-03-13', 'download_count' => 0],
            ['version' => '4.1.6', 'changelog' => 'fix bug youtube check cookie & validate title video max 100 char', 'file_size' => '193.84 MB', 'released_at' => '2026-03-13', 'download_count' => 2],
            ['version' => '4.1.7', 'changelog' => 'Chuyển đổi đăng nhập Oauth callback, fix bug tải video', 'file_size' => '193.9 MB', 'released_at' => '2026-03-16', 'download_count' => 0],
            ['version' => '4.1.8', 'changelog' => 'Thêm tính năng tạo video story AI', 'file_size' => '201.74 MB', 'released_at' => '2026-03-19', 'download_count' => 0],
            ['version' => '4.1.9', 'changelog' => 'fix bug tạo video story AI', 'file_size' => '201.79 MB', 'released_at' => '2026-03-20', 'download_count' => 0],
            ['version' => '4.2.0', 'changelog' => 'fix bug get page facebook', 'file_size' => '201.79 MB', 'released_at' => '2026-03-21', 'download_count' => 1],
            ['version' => '4.2.1', 'changelog' => 'Fix lỗi đăng nhập và xử lý facebook', 'file_size' => '202 MB', 'released_at' => '2026-07-05', 'download_count' => 0],
        ];

        foreach ($releases as $i => $r) {
            Tool::create([
                'name' => 'CMB Core Marketing',
                'slug' => 'cmb-core-marketing-' . str_replace('.', '', $r['version']),
                'type' => 'cmb_core',
                'version' => $r['version'],
                'description' => 'Phần mềm tự động hóa marketing video đa nền tảng CMB Core Marketing.',
                'download_url' => 'https://cdn.cmbcore.com/cmb-core-marketing/CMBcoreMKT%20Setup%20' . $r['version'] . '.exe',
                'file_size' => $r['file_size'],
                'sha256' => null,
                'changelog' => $r['changelog'],
                'is_active' => true,
                'is_latest' => $i === count($releases) - 1,
                'download_count' => $r['download_count'],
                'released_at' => $r['released_at'],
            ]);
        }

        $this->command->info('Seeded 8 cmb_core Tool releases.');
    }
}
```

- [ ] **Step 8: Register the seeder and run it**

In `database/seeders/DatabaseSeeder.php`, add inside `run()`:

```php
        $this->call(ToolSeeder::class);
```

Run: `php artisan db:seed --class=ToolSeeder`
Expected: `Seeded 8 cmb_core Tool releases.` — confirm with `php artisan tinker --execute="echo App\Models\Tool::count();"` → `8`

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_01_000001_create_tools_table.php app/Models/Tool.php database/factories/ToolFactory.php database/seeders/ToolSeeder.php database/seeders/DatabaseSeeder.php tests/Unit/ToolTest.php
git commit -m "Add Tool model, migration, and real cmb_core release data seed"
```

---

### Task 2: `Admin\ToolManagementController` (list, create, edit)

**Files:**
- Create: `app/Http/Controllers/Admin/ToolManagementController.php`
- Create: `resources/views/admin/tools/index.blade.php`
- Create: `resources/views/admin/tools/create.blade.php`
- Create: `resources/views/admin/tools/edit.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/ToolManagementControllerTest.php`

**Interfaces:**
- Consumes: `Tool` (Task 1), `admin.layout` + `admin._partials._breadcrumb` (Phase 3C).
- Produces: `GET /admin/tools` (`admin.tools.index`), `GET /admin/tools/create` (`admin.tools.create`), `POST /admin/tools` (`admin.tools.store`), `GET /admin/tools/{id}/edit` (`admin.tools.edit`), `PUT /admin/tools/{id}` (`admin.tools.update`), `DELETE /admin/tools/{id}` (`admin.tools.destroy`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/ToolManagementControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_index_lists_tools(): void
    {
        Tool::factory()->count(3)->create(['type' => 'cmb_core']);

        $response = $this->actingAsAdmin()->get('/admin/tools');

        $response->assertOk();
        $response->assertViewHas('tools', fn ($tools) => $tools->total() === 3);
    }

    public function test_store_creates_a_new_tool_and_unmarks_previous_latest(): void
    {
        $existingLatest = Tool::factory()->create(['type' => 'cmb_core', 'is_latest' => true]);

        $response = $this->actingAsAdmin()->post('/admin/tools', [
            'name' => 'CMB Core Marketing',
            'slug' => 'cmb-core-marketing-500',
            'type' => 'cmb_core',
            'version' => '5.0.0',
            'download_url' => 'https://cdn.cmbcore.com/x.exe',
            'file_size' => '210 MB',
            'changelog' => 'Big release',
            'is_active' => '1',
            'is_latest' => '1',
        ]);

        $response->assertRedirect(route('admin.tools.index'));
        $this->assertDatabaseHas('tools', ['slug' => 'cmb-core-marketing-500', 'is_latest' => 1]);
        $this->assertFalse($existingLatest->fresh()->is_latest);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/tools', ['name' => 'X'])
            ->assertSessionHasErrors(['slug', 'type', 'version', 'download_url']);
    }

    public function test_update_edits_an_existing_tool(): void
    {
        $tool = Tool::factory()->create(['type' => 'cmb_core', 'changelog' => 'old']);

        $response = $this->actingAsAdmin()->put("/admin/tools/{$tool->id}", [
            'name' => $tool->name,
            'slug' => $tool->slug,
            'type' => $tool->type,
            'version' => $tool->version,
            'download_url' => $tool->download_url,
            'changelog' => 'new changelog',
            'is_active' => '1',
            'is_latest' => '0',
        ]);

        $response->assertRedirect(route('admin.tools.index'));
        $this->assertEquals('new changelog', $tool->fresh()->changelog);
    }

    public function test_destroy_removes_a_tool(): void
    {
        $tool = Tool::factory()->create();

        $this->actingAsAdmin()->delete("/admin/tools/{$tool->id}")->assertRedirect(route('admin.tools.index'));

        $this->assertDatabaseMissing('tools', ['id' => $tool->id]);
    }

    public function test_index_rejects_unauthenticated_requests(): void
    {
        $this->get('/admin/tools')->assertRedirect();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ToolManagementControllerTest`
Expected: FAIL (route not defined)

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Admin/ToolManagementController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ToolManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = Tool::query()->latest('released_at');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $tools = $query->paginate(20)->appends($request->query());
        $types = Tool::distinct()->pluck('type')->filter()->sort()->values();

        return view('admin.tools.index', compact('tools', 'types'));
    }

    public function create()
    {
        return view('admin.tools.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        if ($validated['is_latest'] ?? false) {
            Tool::where('type', $validated['type'])->update(['is_latest' => false]);
        }

        Tool::create($validated);

        return redirect()->route('admin.tools.index')->with('success', 'Đã tạo phiên bản mới.');
    }

    public function edit(int $id)
    {
        $tool = Tool::findOrFail($id);

        return view('admin.tools.edit', compact('tool'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $tool = Tool::findOrFail($id);
        $validated = $this->validated($request);

        if ($validated['is_latest'] ?? false) {
            Tool::where('type', $validated['type'])->where('id', '!=', $tool->id)->update(['is_latest' => false]);
        }

        $tool->update($validated);

        return redirect()->route('admin.tools.index')->with('success', 'Đã cập nhật.');
    }

    public function destroy(int $id): RedirectResponse
    {
        Tool::findOrFail($id)->delete();

        return redirect()->route('admin.tools.index')->with('success', 'Đã xoá.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'slug' => 'required|string|max:191',
            'type' => 'required|string|max:50',
            'version' => 'required|string|max:50',
            'description' => 'nullable|string',
            'download_url' => 'required|url',
            'file_size' => 'nullable|string|max:50',
            'sha256' => 'nullable|string|max:64',
            'changelog' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_latest' => 'nullable|boolean',
            'released_at' => 'nullable|date',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['is_latest'] = $request->boolean('is_latest');

        return $data;
    }
}
```

- [ ] **Step 4: Write the views**

Create `resources/views/admin/tools/index.blade.php`:

```blade
@extends('admin.layout')

@section('title', 'Tools')
@section('page-title', 'Quản lý Tool')

@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Tools']]])
@endsection

@section('content')
@if(session('success'))
<div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card mb-3 border-0 shadow-sm">
    <div class="card-body py-3 d-flex justify-content-between align-items-center">
        <form method="GET" class="d-flex gap-2">
            <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Tất cả loại</option>
                @foreach($types as $t)
                <option value="{{ $t }}" {{ request('type')===$t?'selected':'' }}>{{ $t }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('admin.tools.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Thêm phiên bản</a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Type</th><th>Version</th><th>Size</th><th>Active</th><th>Latest</th><th>Downloads</th><th>Released</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse($tools as $tool)
                    <tr>
                        <td><code>{{ $tool->id }}</code></td>
                        <td>{{ $tool->name }}</td>
                        <td><span class="badge bg-secondary">{{ $tool->type }}</span></td>
                        <td>{{ $tool->version }}</td>
                        <td>{{ $tool->file_size }}</td>
                        <td>{{ $tool->is_active ? 'Yes' : 'No' }}</td>
                        <td>{{ $tool->is_latest ? 'Yes' : 'No' }}</td>
                        <td>{{ number_format($tool->download_count) }}</td>
                        <td>{{ $tool->released_at?->format('d/m/Y') }}</td>
                        <td>
                            <a href="{{ route('admin.tools.edit', $tool->id) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                            <form method="POST" action="{{ route('admin.tools.destroy', $tool->id) }}" class="d-inline" onsubmit="return confirm('Xoá phiên bản này?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="text-center py-4 text-muted">Chưa có tool nào</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($tools->hasPages())
    <div class="card-footer">{{ $tools->links() }}</div>
    @endif
</div>
@endsection
```

Create `resources/views/admin/tools/create.blade.php`:

```blade
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
```

Create `resources/views/admin/tools/edit.blade.php`:

```blade
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
```

Create `resources/views/admin/tools/_form.blade.php` (shared form partial, included by both create/edit — `$tool` is null on create):

```blade
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
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, add the import and, inside the existing `Route::middleware('admin')->group(...)` block, add:

```php
        Route::get('/tools', [ToolManagementController::class, 'index'])->name('tools.index');
        Route::get('/tools/create', [ToolManagementController::class, 'create'])->name('tools.create');
        Route::post('/tools', [ToolManagementController::class, 'store'])->name('tools.store');
        Route::get('/tools/{id}/edit', [ToolManagementController::class, 'edit'])->name('tools.edit');
        Route::put('/tools/{id}', [ToolManagementController::class, 'update'])->name('tools.update');
        Route::delete('/tools/{id}', [ToolManagementController::class, 'destroy'])->name('tools.destroy');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=ToolManagementControllerTest`
Expected: PASS (6 tests)

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/ToolManagementController.php resources/views/admin/tools routes/web.php tests/Feature/Admin/ToolManagementControllerTest.php
git commit -m "Add Admin ToolManagementController with create/edit/delete"
```

---

### Task 3: Desktop-app `Tool` update-check API

**Files:**
- Create: `app/Http/Controllers/API/UpdateCheckController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/UpdateCheckControllerTest.php`

**Interfaces:**
- Consumes: `Tool` (Task 1).
- Produces: `GET /api/cmb/latest-version?type=cmb_core` (no auth — matches the source `UpdateCheckController::getCmbLatestVersion`, called by the desktop app before login is even possible), `GET /api/cmb/versions?type=cmb_core` (no auth).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/UpdateCheckControllerTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_version_returns_the_latest_active_tool_for_type(): void
    {
        Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.2.0', 'is_active' => true, 'is_latest' => false]);
        $latest = Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.2.1', 'is_active' => true, 'is_latest' => true]);

        $response = $this->getJson('/api/cmb/latest-version?type=cmb_core');

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.version', '4.2.1');
        $this->assertEquals($latest->download_url, $response->json('data.download_url'));
    }

    public function test_latest_version_ignores_inactive_tools(): void
    {
        Tool::factory()->create(['type' => 'cmb_core', 'is_active' => false, 'is_latest' => true]);

        $this->getJson('/api/cmb/latest-version?type=cmb_core')->assertStatus(404);
    }

    public function test_latest_version_requires_type(): void
    {
        $this->getJson('/api/cmb/latest-version')->assertStatus(422);
    }

    public function test_versions_returns_all_active_versions_for_type_ordered_newest_first(): void
    {
        Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.1.0', 'released_at' => '2026-01-01', 'is_active' => true]);
        Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.2.0', 'released_at' => '2026-03-01', 'is_active' => true]);
        Tool::factory()->create(['type' => 'cmb_core', 'version' => '4.0.0', 'released_at' => '2025-12-01', 'is_active' => false]);

        $response = $this->getJson('/api/cmb/versions?type=cmb_core');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertEquals('4.2.0', $response->json('data.0.version'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UpdateCheckControllerTest`
Expected: FAIL (route not defined)

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/API/UpdateCheckController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateCheckController extends Controller
{
    public function getCmbLatestVersion(Request $request): JsonResponse
    {
        $request->validate(['type' => 'required|string|max:50']);

        $tool = Tool::where('type', $request->input('type'))
            ->where('is_active', true)
            ->where('is_latest', true)
            ->first();

        if (!$tool) {
            return response()->json(['success' => false, 'error' => 'Không tìm thấy phiên bản mới nhất'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->format($tool)]);
    }

    public function getCmbVersionList(Request $request): JsonResponse
    {
        $request->validate(['type' => 'required|string|max:50']);

        $tools = Tool::where('type', $request->input('type'))
            ->where('is_active', true)
            ->orderByDesc('released_at')
            ->get();

        return response()->json(['success' => true, 'data' => $tools->map(fn ($t) => $this->format($t))->values()]);
    }

    private function format(Tool $tool): array
    {
        return [
            'name' => $tool->name,
            'version' => $tool->version,
            'download_url' => $tool->download_url,
            'file_size' => $tool->file_size,
            'sha256' => $tool->sha256,
            'changelog' => $tool->changelog,
            'released_at' => $tool->released_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/api.php`, add the import near the other `API\` imports, and add a new top-level group (outside `tool`, outside the `auth:sanctum` groups — this endpoint has no auth, matching the source):

```php
use App\Http\Controllers\API\UpdateCheckController;
```

```php
Route::prefix('cmb')->group(function () {
    Route::get('/latest-version', [UpdateCheckController::class, 'getCmbLatestVersion'])->middleware('throttle:30,1,cmb-latest-version');
    Route::get('/versions', [UpdateCheckController::class, 'getCmbVersionList'])->middleware('throttle:30,1,cmb-versions');
});
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=UpdateCheckControllerTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/API/UpdateCheckController.php routes/api.php tests/Feature/Api/UpdateCheckControllerTest.php
git commit -m "Add desktop-app UpdateCheckController for version checks"
```

---

### Task 4: `BlogCategory` and `BlogPost` models, migrations, factories

**Files:**
- Create: `database/migrations/2026_08_01_000002_create_blog_categories_table.php`
- Create: `database/migrations/2026_08_01_000003_create_blog_posts_table.php`
- Create: `app/Models/BlogCategory.php`
- Create: `app/Models/BlogPost.php`
- Create: `database/factories/BlogCategoryFactory.php`
- Create: `database/factories/BlogPostFactory.php`
- Test: `tests/Unit/BlogCategoryTest.php`, `tests/Unit/BlogPostTest.php`

**Interfaces:**
- Produces: `BlogCategory` (fillable: `name, slug, description, order, is_active, meta_title, meta_description, meta_keywords`; relation `posts(): HasMany`). `BlogPost` (fillable: `category_id, author_id, title, slug, excerpt, content, featured_image, is_published, published_at, views, meta_title, meta_description, meta_keywords, og_image`; casts `is_published` boolean, `views` integer, `published_at` datetime; relations `category(): BelongsTo`, `author(): BelongsTo` to `User`). Consumed by Task 5 (admin CRUD).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/BlogCategoryTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_relation_returns_posts_in_the_category(): void
    {
        $category = BlogCategory::factory()->create();
        BlogPost::factory()->count(2)->create(['category_id' => $category->id]);
        BlogPost::factory()->create();

        $this->assertCount(2, $category->posts);
    }
}
```

Create `tests/Unit/BlogPostTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_and_author_relations_resolve(): void
    {
        $category = BlogCategory::factory()->create();
        $author = User::factory()->create();
        $post = BlogPost::factory()->create(['category_id' => $category->id, 'author_id' => $author->id]);

        $this->assertTrue($post->category->is($category));
        $this->assertTrue($post->author->is($author));
    }

    public function test_surviving_category_deletion_nulls_category_id(): void
    {
        $category = BlogCategory::factory()->create();
        $post = BlogPost::factory()->create(['category_id' => $category->id]);

        $category->delete();

        $this->assertNull($post->fresh()->category_id);
    }

    public function test_casts_are_applied(): void
    {
        $post = BlogPost::factory()->create(['is_published' => true, 'views' => 42]);

        $this->assertIsBool($post->fresh()->is_published);
        $this->assertIsInt($post->fresh()->views);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=BlogCategoryTest` and `php artisan test --filter=BlogPostTest`
Expected: FAIL (`Class "App\Models\BlogCategory" not found`)

- [ ] **Step 3: Write the migrations**

Create `database/migrations/2026_08_01_000002_create_blog_categories_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_categories');
    }
};
```

Create `database/migrations/2026_08_01_000003_create_blog_posts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('featured_image')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('og_image')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
```

- [ ] **Step 4: Write the models**

Create `app/Models/BlogCategory.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BlogCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'order', 'is_active',
        'meta_title', 'meta_description', 'meta_keywords',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'category_id');
    }
}
```

Create `app/Models/BlogPost.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'author_id', 'title', 'slug', 'excerpt', 'content',
        'featured_image', 'is_published', 'published_at', 'views',
        'meta_title', 'meta_description', 'meta_keywords', 'og_image',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'views' => 'integer',
        'published_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
```

- [ ] **Step 5: Write the factories**

Create `database/factories/BlogCategoryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BlogCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlogCategoryFactory extends Factory
{
    protected $model = BlogCategory::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => \Illuminate\Support\Str::slug($name),
            'description' => $this->faker->sentence(),
            'order' => 0,
            'is_active' => true,
        ];
    }
}
```

Create `database/factories/BlogPostFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BlogPostFactory extends Factory
{
    protected $model = BlogPost::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->sentence();

        return [
            'category_id' => BlogCategory::factory(),
            'author_id' => User::factory(),
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title) . '-' . $this->faker->unique()->numberBetween(1, 100000),
            'excerpt' => $this->faker->sentence(),
            'content' => $this->faker->paragraphs(3, true),
            'is_published' => false,
            'views' => 0,
        ];
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=BlogCategoryTest` then `php artisan test --filter=BlogPostTest`
Expected: PASS (1 test, 3 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_01_000002_create_blog_categories_table.php database/migrations/2026_08_01_000003_create_blog_posts_table.php app/Models/BlogCategory.php app/Models/BlogPost.php database/factories/BlogCategoryFactory.php database/factories/BlogPostFactory.php tests/Unit/BlogCategoryTest.php tests/Unit/BlogPostTest.php
git commit -m "Add BlogCategory and BlogPost models and migrations"
```

---

### Task 5: `Admin\BlogManagementController` (categories + posts CRUD)

**Files:**
- Create: `app/Http/Controllers/Admin/BlogManagementController.php`
- Create: `resources/views/admin/blog/categories/index.blade.php`
- Create: `resources/views/admin/blog/categories/_form.blade.php`
- Create: `resources/views/admin/blog/categories/create.blade.php`
- Create: `resources/views/admin/blog/categories/edit.blade.php`
- Create: `resources/views/admin/blog/posts/index.blade.php`
- Create: `resources/views/admin/blog/posts/_form.blade.php`
- Create: `resources/views/admin/blog/posts/create.blade.php`
- Create: `resources/views/admin/blog/posts/edit.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/BlogManagementControllerTest.php`

**Interfaces:**
- Consumes: `BlogCategory`, `BlogPost` (Task 4), `admin.layout` (Phase 3C).
- Produces: `admin.blog.categories.{index,create,store,edit,update,destroy}`, `admin.blog.posts.{index,create,store,edit,update,destroy}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/BlogManagementControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_category_store_creates_a_category(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/blog/categories', [
            'name' => 'Marketing Tips',
            'slug' => 'marketing-tips',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.blog.categories.index'));
        $this->assertDatabaseHas('blog_categories', ['slug' => 'marketing-tips']);
    }

    public function test_category_index_lists_categories(): void
    {
        BlogCategory::factory()->count(2)->create();

        $this->actingAsAdmin()->get('/admin/blog/categories')->assertOk();
    }

    public function test_post_store_creates_a_post_and_sets_author_to_current_admin(): void
    {
        $category = BlogCategory::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post('/admin/blog/posts', [
            'category_id' => $category->id,
            'title' => 'How to automate video marketing',
            'slug' => 'how-to-automate-video-marketing',
            'content' => 'Full article content here.',
            'is_published' => '1',
        ]);

        $response->assertRedirect(route('admin.blog.posts.index'));
        $this->assertDatabaseHas('blog_posts', ['slug' => 'how-to-automate-video-marketing', 'author_id' => $admin->id]);
    }

    public function test_post_store_validates_required_fields(): void
    {
        $this->actingAsAdmin()
            ->post('/admin/blog/posts', ['title' => 'X'])
            ->assertSessionHasErrors(['slug', 'content']);
    }

    public function test_post_update_edits_an_existing_post(): void
    {
        $post = BlogPost::factory()->create(['title' => 'Old title']);

        $response = $this->actingAsAdmin()->put("/admin/blog/posts/{$post->id}", [
            'category_id' => $post->category_id,
            'title' => 'New title',
            'slug' => $post->slug,
            'content' => $post->content,
            'is_published' => '0',
        ]);

        $response->assertRedirect(route('admin.blog.posts.index'));
        $this->assertEquals('New title', $post->fresh()->title);
    }

    public function test_post_index_rejects_unauthenticated_requests(): void
    {
        $this->get('/admin/blog/posts')->assertRedirect();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BlogManagementControllerTest`
Expected: FAIL (route not defined)

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Admin/BlogManagementController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BlogManagementController extends Controller
{
    // ── Categories ──────────────────────────────────────────────────

    public function categoriesIndex()
    {
        $categories = BlogCategory::withCount('posts')->orderBy('order')->paginate(20);

        return view('admin.blog.categories.index', compact('categories'));
    }

    public function categoriesCreate()
    {
        return view('admin.blog.categories.create');
    }

    public function categoriesStore(Request $request): RedirectResponse
    {
        $validated = $this->validatedCategory($request);
        BlogCategory::create($validated);

        return redirect()->route('admin.blog.categories.index')->with('success', 'Đã tạo chuyên mục.');
    }

    public function categoriesEdit(int $id)
    {
        $category = BlogCategory::findOrFail($id);

        return view('admin.blog.categories.edit', compact('category'));
    }

    public function categoriesUpdate(Request $request, int $id): RedirectResponse
    {
        $category = BlogCategory::findOrFail($id);
        $category->update($this->validatedCategory($request));

        return redirect()->route('admin.blog.categories.index')->with('success', 'Đã cập nhật.');
    }

    public function categoriesDestroy(int $id): RedirectResponse
    {
        BlogCategory::findOrFail($id)->delete();

        return redirect()->route('admin.blog.categories.index')->with('success', 'Đã xoá.');
    }

    private function validatedCategory(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'slug' => 'required|string|max:191',
            'description' => 'nullable|string',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'meta_title' => 'nullable|string|max:191',
            'meta_description' => 'nullable|string|max:500',
            'meta_keywords' => 'nullable|string|max:500',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['order'] = $data['order'] ?? 0;

        return $data;
    }

    // ── Posts ────────────────────────────────────────────────────────

    public function postsIndex(Request $request)
    {
        $query = BlogPost::with('category')->latest();

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        $posts = $query->paginate(20)->appends($request->query());
        $categories = BlogCategory::orderBy('name')->get();

        return view('admin.blog.posts.index', compact('posts', 'categories'));
    }

    public function postsCreate()
    {
        $categories = BlogCategory::orderBy('name')->get();

        return view('admin.blog.posts.create', compact('categories'));
    }

    public function postsStore(Request $request): RedirectResponse
    {
        $validated = $this->validatedPost($request);
        $validated['author_id'] = $request->user()->id;

        if ($validated['is_published'] && empty($validated['published_at'])) {
            $validated['published_at'] = now();
        }

        BlogPost::create($validated);

        return redirect()->route('admin.blog.posts.index')->with('success', 'Đã tạo bài viết.');
    }

    public function postsEdit(int $id)
    {
        $post = BlogPost::findOrFail($id);
        $categories = BlogCategory::orderBy('name')->get();

        return view('admin.blog.posts.edit', compact('post', 'categories'));
    }

    public function postsUpdate(Request $request, int $id): RedirectResponse
    {
        $post = BlogPost::findOrFail($id);
        $validated = $this->validatedPost($request);

        if ($validated['is_published'] && empty($post->published_at) && empty($validated['published_at'])) {
            $validated['published_at'] = now();
        }

        $post->update($validated);

        return redirect()->route('admin.blog.posts.index')->with('success', 'Đã cập nhật.');
    }

    public function postsDestroy(int $id): RedirectResponse
    {
        BlogPost::findOrFail($id)->delete();

        return redirect()->route('admin.blog.posts.index')->with('success', 'Đã xoá.');
    }

    private function validatedPost(Request $request): array
    {
        $data = $request->validate([
            'category_id' => 'nullable|exists:blog_categories,id',
            'title' => 'required|string|max:191',
            'slug' => 'required|string|max:191',
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string',
            'featured_image' => 'nullable|string|max:500',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:191',
            'meta_description' => 'nullable|string|max:500',
            'meta_keywords' => 'nullable|string|max:500',
            'og_image' => 'nullable|string|max:500',
        ]);

        $data['is_published'] = $request->boolean('is_published');

        return $data;
    }
}
```

- [ ] **Step 4: Write the views**

Create `resources/views/admin/blog/categories/_form.blade.php`:

```blade
@php($category = $category ?? null)

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $category->name ?? '') }}">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Slug</label>
        <input type="text" name="slug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug', $category->slug ?? '') }}">
        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 mb-3">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2">{{ old('description', $category->description ?? '') }}</textarea>
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">Order</label>
        <input type="number" name="order" class="form-control" value="{{ old('order', $category->order ?? 0) }}">
    </div>
    <div class="col-md-8 mb-3 d-flex align-items-end">
        <div class="form-check">
            <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active" {{ old('is_active', $category->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
</div>
```

Create `resources/views/admin/blog/categories/create.blade.php`:

```blade
@extends('admin.layout')
@section('title', 'Thêm chuyên mục')
@section('page-title', 'Thêm chuyên mục blog')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Categories', 'url' => route('admin.blog.categories.index')], ['label' => 'Thêm mới']]])
@endsection
@section('content')
<div class="card border-0 shadow-sm"><div class="card-body">
    <form method="POST" action="{{ route('admin.blog.categories.store') }}">
        @csrf
        @include('admin.blog.categories._form')
        <button type="submit" class="btn btn-primary">Tạo</button>
    </form>
</div></div>
@endsection
```

Create `resources/views/admin/blog/categories/edit.blade.php`:

```blade
@extends('admin.layout')
@section('title', 'Sửa chuyên mục')
@section('page-title', 'Sửa chuyên mục #' . $category->id)
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Categories', 'url' => route('admin.blog.categories.index')], ['label' => 'Sửa']]])
@endsection
@section('content')
<div class="card border-0 shadow-sm"><div class="card-body">
    <form method="POST" action="{{ route('admin.blog.categories.update', $category->id) }}">
        @csrf @method('PUT')
        @include('admin.blog.categories._form', ['category' => $category])
        <button type="submit" class="btn btn-primary">Lưu</button>
    </form>
</div></div>
@endsection
```

Create `resources/views/admin/blog/categories/index.blade.php`:

```blade
@extends('admin.layout')
@section('title', 'Blog Categories')
@section('page-title', 'Chuyên mục Blog')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Categories']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="mb-3"><a href="{{ route('admin.blog.categories.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Thêm chuyên mục</a></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>Name</th><th>Slug</th><th>Posts</th><th>Active</th><th></th></tr></thead>
        <tbody>
        @forelse($categories as $c)
            <tr>
                <td>{{ $c->id }}</td><td>{{ $c->name }}</td><td>{{ $c->slug }}</td><td>{{ $c->posts_count }}</td>
                <td>{{ $c->is_active ? 'Yes' : 'No' }}</td>
                <td>
                    <a href="{{ route('admin.blog.categories.edit', $c->id) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                    <form method="POST" action="{{ route('admin.blog.categories.destroy', $c->id) }}" class="d-inline" onsubmit="return confirm('Xoá?')">
                        @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center py-4 text-muted">Chưa có chuyên mục nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($categories->hasPages())<div class="card-footer">{{ $categories->links() }}</div>@endif</div>
@endsection
```

Create `resources/views/admin/blog/posts/_form.blade.php`:

```blade
@php($post = $post ?? null)

<div class="row">
    <div class="col-md-8 mb-3">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $post->title ?? '') }}">
        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4 mb-3">
        <label class="form-label">Category</label>
        <select name="category_id" class="form-select">
            <option value="">— None —</option>
            @foreach($categories as $c)
            <option value="{{ $c->id }}" {{ old('category_id', $post->category_id ?? '') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-12 mb-3">
        <label class="form-label">Slug</label>
        <input type="text" name="slug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug', $post->slug ?? '') }}">
        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 mb-3">
        <label class="form-label">Excerpt</label>
        <textarea name="excerpt" class="form-control" rows="2">{{ old('excerpt', $post->excerpt ?? '') }}</textarea>
    </div>
    <div class="col-12 mb-3">
        <label class="form-label">Content</label>
        <textarea name="content" class="form-control @error('content') is-invalid @enderror" rows="10">{{ old('content', $post->content ?? '') }}</textarea>
        @error('content')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Featured image URL</label>
        <input type="text" name="featured_image" class="form-control" value="{{ old('featured_image', $post->featured_image ?? '') }}">
    </div>
    <div class="col-md-6 mb-3 d-flex align-items-end">
        <div class="form-check">
            <input type="checkbox" name="is_published" value="1" class="form-check-input" id="is_published" {{ old('is_published', $post->is_published ?? false) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_published">Published</label>
        </div>
    </div>
</div>
```

Create `resources/views/admin/blog/posts/create.blade.php`:

```blade
@extends('admin.layout')
@section('title', 'Thêm bài viết')
@section('page-title', 'Thêm bài viết Blog')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Posts', 'url' => route('admin.blog.posts.index')], ['label' => 'Thêm mới']]])
@endsection
@section('content')
<div class="card border-0 shadow-sm"><div class="card-body">
    <form method="POST" action="{{ route('admin.blog.posts.store') }}">
        @csrf
        @include('admin.blog.posts._form', ['categories' => $categories])
        <button type="submit" class="btn btn-primary">Tạo</button>
    </form>
</div></div>
@endsection
```

Create `resources/views/admin/blog/posts/edit.blade.php`:

```blade
@extends('admin.layout')
@section('title', 'Sửa bài viết')
@section('page-title', 'Sửa bài viết #' . $post->id)
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Posts', 'url' => route('admin.blog.posts.index')], ['label' => 'Sửa']]])
@endsection
@section('content')
<div class="card border-0 shadow-sm"><div class="card-body">
    <form method="POST" action="{{ route('admin.blog.posts.update', $post->id) }}">
        @csrf @method('PUT')
        @include('admin.blog.posts._form', ['post' => $post, 'categories' => $categories])
        <button type="submit" class="btn btn-primary">Lưu</button>
    </form>
</div></div>
@endsection
```

Create `resources/views/admin/blog/posts/index.blade.php`:

```blade
@extends('admin.layout')
@section('title', 'Blog Posts')
@section('page-title', 'Bài viết Blog')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Blog Posts']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="mb-3"><a href="{{ route('admin.blog.posts.create') }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Thêm bài viết</a></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Published</th><th>Views</th><th></th></tr></thead>
        <tbody>
        @forelse($posts as $p)
            <tr>
                <td>{{ $p->id }}</td><td>{{ $p->title }}</td><td>{{ $p->category->name ?? '—' }}</td>
                <td>{{ $p->is_published ? 'Yes' : 'No' }}</td><td>{{ number_format($p->views) }}</td>
                <td>
                    <a href="{{ route('admin.blog.posts.edit', $p->id) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                    <form method="POST" action="{{ route('admin.blog.posts.destroy', $p->id) }}" class="d-inline" onsubmit="return confirm('Xoá?')">
                        @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center py-4 text-muted">Chưa có bài viết nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($posts->hasPages())<div class="card-footer">{{ $posts->links() }}</div>@endif</div>
@endsection
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, add the import and, inside the `admin` middleware group:

```php
        Route::prefix('blog')->name('blog.')->group(function () {
            Route::get('/categories', [BlogManagementController::class, 'categoriesIndex'])->name('categories.index');
            Route::get('/categories/create', [BlogManagementController::class, 'categoriesCreate'])->name('categories.create');
            Route::post('/categories', [BlogManagementController::class, 'categoriesStore'])->name('categories.store');
            Route::get('/categories/{id}/edit', [BlogManagementController::class, 'categoriesEdit'])->name('categories.edit');
            Route::put('/categories/{id}', [BlogManagementController::class, 'categoriesUpdate'])->name('categories.update');
            Route::delete('/categories/{id}', [BlogManagementController::class, 'categoriesDestroy'])->name('categories.destroy');

            Route::get('/posts', [BlogManagementController::class, 'postsIndex'])->name('posts.index');
            Route::get('/posts/create', [BlogManagementController::class, 'postsCreate'])->name('posts.create');
            Route::post('/posts', [BlogManagementController::class, 'postsStore'])->name('posts.store');
            Route::get('/posts/{id}/edit', [BlogManagementController::class, 'postsEdit'])->name('posts.edit');
            Route::put('/posts/{id}', [BlogManagementController::class, 'postsUpdate'])->name('posts.update');
            Route::delete('/posts/{id}', [BlogManagementController::class, 'postsDestroy'])->name('posts.destroy');
        });
```

This produces route names `admin.blog.categories.index`, `admin.blog.posts.index`, etc., matching the controller's `redirect()->route(...)` calls above.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=BlogManagementControllerTest`
Expected: PASS (6 tests)

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/BlogManagementController.php resources/views/admin/blog routes/web.php tests/Feature/Admin/BlogManagementControllerTest.php
git commit -m "Add Admin BlogManagementController for categories and posts"
```

---

### Task 6: `ContactMessage` and `Preorder` models + admin list/status views

**Files:**
- Create: `database/migrations/2026_08_01_000004_create_contact_messages_table.php`
- Create: `database/migrations/2026_08_01_000005_create_preorders_table.php`
- Create: `app/Models/ContactMessage.php`
- Create: `app/Models/Preorder.php`
- Create: `database/factories/ContactMessageFactory.php`
- Create: `database/factories/PreorderFactory.php`
- Create: `app/Http/Controllers/Admin/InquiryManagementController.php`
- Create: `resources/views/admin/contact-messages/index.blade.php`
- Create: `resources/views/admin/preorders/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/InquiryManagementControllerTest.php`

**Interfaces:**
- Produces: `ContactMessage` (fillable: `name, email, subject, message, status, admin_notes`, default `status='new'`). `Preorder` (fillable: `fullname, email, phone, product_version, early_access, status, notes`, casts `early_access` boolean, default `status='pending'`). `admin.contact-messages.{index,updateStatus}`, `admin.preorders.{index,updateStatus}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/InquiryManagementControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\ContactMessage;
use App\Models\Preorder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_contact_messages_index_lists_messages(): void
    {
        ContactMessage::factory()->count(3)->create();

        $response = $this->actingAsAdmin()->get('/admin/contact-messages');

        $response->assertOk();
        $response->assertViewHas('messages', fn ($m) => $m->total() === 3);
    }

    public function test_contact_messages_update_status_changes_status_and_notes(): void
    {
        $message = ContactMessage::factory()->create(['status' => 'new']);

        $response = $this->actingAsAdmin()->put("/admin/contact-messages/{$message->id}", [
            'status' => 'resolved',
            'admin_notes' => 'Called back, resolved.',
        ]);

        $response->assertRedirect(route('admin.contact-messages.index'));
        $this->assertEquals('resolved', $message->fresh()->status);
        $this->assertEquals('Called back, resolved.', $message->fresh()->admin_notes);
    }

    public function test_preorders_index_lists_preorders(): void
    {
        Preorder::factory()->count(2)->create();

        $this->actingAsAdmin()->get('/admin/preorders')->assertOk();
    }

    public function test_preorders_update_status_changes_status(): void
    {
        $preorder = Preorder::factory()->create(['status' => 'pending']);

        $response = $this->actingAsAdmin()->put("/admin/preorders/{$preorder->id}", ['status' => 'contacted']);

        $response->assertRedirect(route('admin.preorders.index'));
        $this->assertEquals('contacted', $preorder->fresh()->status);
    }

    public function test_contact_messages_index_rejects_unauthenticated_requests(): void
    {
        $this->get('/admin/contact-messages')->assertRedirect();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InquiryManagementControllerTest`
Expected: FAIL (`Class "App\Models\ContactMessage" not found`)

- [ ] **Step 3: Write the migrations**

Create `database/migrations/2026_08_01_000004_create_contact_messages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('status')->default('new'); // new, in_progress, resolved
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
```

Create `database/migrations/2026_08_01_000005_create_preorders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preorders', function (Blueprint $table) {
            $table->id();
            $table->string('fullname');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('product_version')->nullable();
            $table->boolean('early_access')->default(false);
            $table->string('status')->default('pending'); // pending, contacted, converted, cancelled
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preorders');
    }
};
```

- [ ] **Step 4: Write the models**

Create `app/Models/ContactMessage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'subject', 'message', 'status', 'admin_notes'];
}
```

Create `app/Models/Preorder.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Preorder extends Model
{
    use HasFactory;

    protected $fillable = ['fullname', 'email', 'phone', 'product_version', 'early_access', 'status', 'notes'];

    protected $casts = [
        'early_access' => 'boolean',
    ];
}
```

- [ ] **Step 5: Write the factories**

Create `database/factories/ContactMessageFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactMessageFactory extends Factory
{
    protected $model = ContactMessage::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'subject' => $this->faker->sentence(4),
            'message' => $this->faker->paragraph(),
            'status' => 'new',
        ];
    }
}
```

Create `database/factories/PreorderFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Preorder;
use Illuminate\Database\Eloquent\Factories\Factory;

class PreorderFactory extends Factory
{
    protected $model = Preorder::class;

    public function definition(): array
    {
        return [
            'fullname' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'product_version' => 'cmb_core',
            'early_access' => false,
            'status' => 'pending',
        ];
    }
}
```

- [ ] **Step 6: Write the controller**

Create `app/Http/Controllers/Admin/InquiryManagementController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Preorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InquiryManagementController extends Controller
{
    public function contactMessagesIndex(Request $request)
    {
        $query = ContactMessage::latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $messages = $query->paginate(20)->appends($request->query());

        return view('admin.contact-messages.index', compact('messages'));
    }

    public function contactMessagesUpdateStatus(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:new,in_progress,resolved',
            'admin_notes' => 'nullable|string',
        ]);

        ContactMessage::findOrFail($id)->update($validated);

        return redirect()->route('admin.contact-messages.index')->with('success', 'Đã cập nhật.');
    }

    public function preordersIndex(Request $request)
    {
        $query = Preorder::latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $preorders = $query->paginate(20)->appends($request->query());

        return view('admin.preorders.index', compact('preorders'));
    }

    public function preordersUpdateStatus(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,contacted,converted,cancelled',
        ]);

        Preorder::findOrFail($id)->update($validated);

        return redirect()->route('admin.preorders.index')->with('success', 'Đã cập nhật.');
    }
}
```

- [ ] **Step 7: Write the views**

Create `resources/views/admin/contact-messages/index.blade.php`:

```blade
@extends('admin.layout')
@section('title', 'Contact Messages')
@section('page-title', 'Liên hệ từ khách hàng')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Contact Messages']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Subject</th><th>Message</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($messages as $m)
            <tr>
                <td>{{ $m->id }}</td><td>{{ $m->name }}</td><td>{{ $m->email }}</td><td>{{ $m->subject }}</td>
                <td><small title="{{ $m->message }}">{{ \Illuminate\Support\Str::limit($m->message, 60) }}</small></td>
                <td>
                    <form method="POST" action="{{ route('admin.contact-messages.update', $m->id) }}" class="d-flex gap-1">
                        @csrf @method('PUT')
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="new" {{ $m->status==='new'?'selected':'' }}>New</option>
                            <option value="in_progress" {{ $m->status==='in_progress'?'selected':'' }}>In Progress</option>
                            <option value="resolved" {{ $m->status==='resolved'?'selected':'' }}>Resolved</option>
                        </select>
                        <input type="hidden" name="admin_notes" value="{{ $m->admin_notes }}">
                    </form>
                </td>
                <td>{{ $m->created_at->format('d/m/Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center py-4 text-muted">Chưa có liên hệ nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($messages->hasPages())<div class="card-footer">{{ $messages->links() }}</div>@endif</div>
@endsection
```

Create `resources/views/admin/preorders/index.blade.php`:

```blade
@extends('admin.layout')
@section('title', 'Preorders')
@section('page-title', 'Đăng ký Preorder')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Preorders']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>Fullname</th><th>Email</th><th>Phone</th><th>Version</th><th>Early Access</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($preorders as $p)
            <tr>
                <td>{{ $p->id }}</td><td>{{ $p->fullname }}</td><td>{{ $p->email }}</td><td>{{ $p->phone }}</td>
                <td>{{ $p->product_version }}</td><td>{{ $p->early_access ? 'Yes' : 'No' }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.preorders.update', $p->id) }}">
                        @csrf @method('PUT')
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="pending" {{ $p->status==='pending'?'selected':'' }}>Pending</option>
                            <option value="contacted" {{ $p->status==='contacted'?'selected':'' }}>Contacted</option>
                            <option value="converted" {{ $p->status==='converted'?'selected':'' }}>Converted</option>
                            <option value="cancelled" {{ $p->status==='cancelled'?'selected':'' }}>Cancelled</option>
                        </select>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center py-4 text-muted">Chưa có preorder nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($preorders->hasPages())<div class="card-footer">{{ $preorders->links() }}</div>@endif</div>
@endsection
```

- [ ] **Step 8: Register the routes**

In `routes/web.php`, add the import and, inside the `admin` middleware group:

```php
        Route::get('/contact-messages', [InquiryManagementController::class, 'contactMessagesIndex'])->name('contact-messages.index');
        Route::put('/contact-messages/{id}', [InquiryManagementController::class, 'contactMessagesUpdateStatus'])->name('contact-messages.update');

        Route::get('/preorders', [InquiryManagementController::class, 'preordersIndex'])->name('preorders.index');
        Route::put('/preorders/{id}', [InquiryManagementController::class, 'preordersUpdateStatus'])->name('preorders.update');
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=InquiryManagementControllerTest`
Expected: PASS (5 tests)

- [ ] **Step 10: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_01_000004_create_contact_messages_table.php database/migrations/2026_08_01_000005_create_preorders_table.php app/Models/ContactMessage.php app/Models/Preorder.php database/factories/ContactMessageFactory.php database/factories/PreorderFactory.php app/Http/Controllers/Admin/InquiryManagementController.php resources/views/admin/contact-messages resources/views/admin/preorders routes/web.php tests/Feature/Admin/InquiryManagementControllerTest.php
git commit -m "Add ContactMessage and Preorder models with admin status management"
```

---

### Task 7: `BugReport` model + admin view + desktop-app submission API

**Files:**
- Create: `database/migrations/2026_08_01_000006_create_bug_reports_table.php`
- Create: `app/Models/BugReport.php`
- Create: `database/factories/BugReportFactory.php`
- Create: `app/Http/Requests/SubmitBugReportRequest.php`
- Create: `app/Http/Controllers/API/BugReportController.php`
- Create: `app/Http/Controllers/Admin/BugReportManagementController.php`
- Create: `resources/views/admin/bug-reports/index.blade.php`
- Modify: `routes/api.php`, `routes/web.php`
- Test: `tests/Unit/BugReportTest.php`, `tests/Feature/Api/BugReportControllerTest.php`, `tests/Feature/Admin/BugReportManagementControllerTest.php`

**Interfaces:**
- Consumes: `User` (Phase 1), `Storage::disk('public')` (existing pattern from `OpenAiImageService`, Phase 3D).
- Produces: `BugReport` model (fillable: `user_id, description, screenshots, screenshot_count, app_version, device_info, ip_address, user_agent, status`, casts `screenshots` array, default `status='pending'`). `POST /api/bug-reports` (auth required). `admin.bug-reports.{index,updateStatus}`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/BugReportTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\BugReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BugReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_screenshots_cast_to_array(): void
    {
        $report = BugReport::factory()->create([
            'screenshots' => ['https://example.test/a.png', 'https://example.test/b.png'],
        ]);

        $this->assertIsArray($report->fresh()->screenshots);
        $this->assertCount(2, $report->fresh()->screenshots);
    }

    public function test_user_deletion_preserves_the_bug_report_with_null_user_id(): void
    {
        $user = User::factory()->create();
        $report = BugReport::factory()->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertNull($report->fresh()->user_id);
    }
}
```

Create `tests/Feature/Api/BugReportControllerTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BugReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_submit_creates_a_bug_report_without_screenshots(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/bug-reports', [
                'description' => 'Không upload tự động được video tiktok',
                'app_version' => '4.2.1',
            ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('bug_reports', ['user_id' => $user->id, 'app_version' => '4.2.1', 'status' => 'pending']);
    }

    public function test_submit_uploads_screenshots_to_local_storage(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('screenshot.png');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/bug-reports', [
                'description' => 'Bug with screenshot',
                'screenshots' => [$file],
            ]);

        $response->assertStatus(201);
        $report = \App\Models\BugReport::first();
        $this->assertEquals(1, $report->screenshot_count);
        $this->assertStringContainsString('/storage/bug-reports/', $report->screenshots[0]);
    }

    public function test_submit_requires_description(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/bug-reports', [])
            ->assertStatus(422);
    }

    public function test_submit_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/bug-reports', ['description' => 'x'])->assertStatus(401);
    }
}
```

Create `tests/Feature/Admin/BugReportManagementControllerTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\BugReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BugReportManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        return $this;
    }

    public function test_index_lists_bug_reports(): void
    {
        BugReport::factory()->count(2)->create();

        $response = $this->actingAsAdmin()->get('/admin/bug-reports');

        $response->assertOk();
        $response->assertViewHas('reports', fn ($r) => $r->total() === 2);
    }

    public function test_update_status_changes_status(): void
    {
        $report = BugReport::factory()->create(['status' => 'pending']);

        $response = $this->actingAsAdmin()->put("/admin/bug-reports/{$report->id}", ['status' => 'resolved']);

        $response->assertRedirect(route('admin.bug-reports.index'));
        $this->assertEquals('resolved', $report->fresh()->status);
    }

    public function test_index_rejects_unauthenticated_requests(): void
    {
        $this->get('/admin/bug-reports')->assertRedirect();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=BugReportTest`, `--filter=BugReportControllerTest`, `--filter=BugReportManagementControllerTest`
Expected: FAIL (`Class "App\Models\BugReport" not found`)

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_01_000006_create_bug_reports_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->id();
            // nullOnDelete (not cascade): a bug report is a support-ticket-style
            // record worth keeping for history even if the reporting user's
            // account is later deleted — same reasoning as feature_usages.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->json('screenshots')->nullable();
            $table->unsignedInteger('screenshot_count')->default(0);
            $table->string('app_version')->nullable();
            $table->text('device_info')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status')->default('pending'); // pending, in_progress, resolved, wont_fix
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_reports');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/BugReport.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BugReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'description', 'screenshots', 'screenshot_count',
        'app_version', 'device_info', 'ip_address', 'user_agent', 'status',
    ];

    protected $casts = [
        'screenshots' => 'array',
        'screenshot_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/BugReportFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BugReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BugReportFactory extends Factory
{
    protected $model = BugReport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'description' => $this->faker->sentence(),
            'screenshots' => null,
            'screenshot_count' => 0,
            'app_version' => '4.2.1',
            'status' => 'pending',
        ];
    }
}
```

- [ ] **Step 6: Run the unit test to verify it passes**

Run: `php artisan test --filter=BugReportTest`
Expected: PASS (2 tests)

- [ ] **Step 7: Write the API request and controller**

Create `app/Http/Requests/SubmitBugReportRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitBugReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'description' => 'required|string|max:5000',
            'app_version' => 'nullable|string|max:50',
            'device_info' => 'nullable|string|max:1000',
            'screenshots' => 'nullable|array|max:5',
            'screenshots.*' => 'file|image|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Mô tả lỗi là bắt buộc (description is required).',
            'screenshots.max' => 'Tối đa 5 ảnh chụp màn hình (max 5 screenshots).',
            'screenshots.*.image' => 'File phải là hình ảnh (must be an image).',
            'screenshots.*.max' => 'Ảnh tối đa 5MB (max 5MB per image).',
        ];
    }
}
```

Create `app/Http/Controllers/API/BugReportController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitBugReportRequest;
use App\Models\BugReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BugReportController extends Controller
{
    public function submit(SubmitBugReportRequest $request): JsonResponse
    {
        $user = $request->user();

        $screenshotUrls = [];
        foreach ($request->file('screenshots', []) as $file) {
            $filename = 'bug-reports/' . Str::uuid() . '.' . $file->getClientOriginalExtension();
            Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));
            $screenshotUrls[] = Storage::disk('public')->url($filename);
        }

        $report = BugReport::create([
            'user_id' => $user->id,
            'description' => $request->input('description'),
            'screenshots' => $screenshotUrls ?: null,
            'screenshot_count' => count($screenshotUrls),
            'app_version' => $request->input('app_version'),
            'device_info' => $request->input('device_info'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'data' => ['id' => $report->id],
        ], 201);
    }
}
```

- [ ] **Step 8: Register the API route**

In `routes/api.php`, add the import, and add inside the existing `Route::middleware(['auth:sanctum', 'token.version'])->group(function () { ... });` block that already holds `/me`, `/logout`, `/account/*` (NOT the `tool` prefix group — this isn't a credit-gated AI tool):

```php
    Route::post('/bug-reports', [BugReportController::class, 'submit'])->middleware('throttle:10,1,bug-reports');
```

- [ ] **Step 9: Run the API test to verify it passes**

Run: `php artisan test --filter=BugReportControllerTest`
Expected: PASS (4 tests)

- [ ] **Step 10: Write the admin controller and view**

Create `app/Http/Controllers/Admin/BugReportManagementController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BugReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BugReportManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = BugReport::with('user')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $reports = $query->paginate(20)->appends($request->query());

        return view('admin.bug-reports.index', compact('reports'));
    }

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,in_progress,resolved,wont_fix',
        ]);

        BugReport::findOrFail($id)->update($validated);

        return redirect()->route('admin.bug-reports.index')->with('success', 'Đã cập nhật.');
    }
}
```

Create `resources/views/admin/bug-reports/index.blade.php`:

```blade
@extends('admin.layout')
@section('title', 'Bug Reports')
@section('page-title', 'Báo cáo lỗi')
@section('breadcrumb')
@include('admin._partials._breadcrumb', ['items' => [['label' => 'Bug Reports']]])
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>User</th><th>Description</th><th>App Version</th><th>Screenshots</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
        @forelse($reports as $r)
            <tr>
                <td>{{ $r->id }}</td>
                <td>{{ $r->user->name ?? 'Deleted' }}</td>
                <td><small title="{{ $r->description }}">{{ \Illuminate\Support\Str::limit($r->description, 60) }}</small></td>
                <td>{{ $r->app_version }}</td>
                <td>{{ $r->screenshot_count }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.bug-reports.update', $r->id) }}">
                        @csrf @method('PUT')
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="pending" {{ $r->status==='pending'?'selected':'' }}>Pending</option>
                            <option value="in_progress" {{ $r->status==='in_progress'?'selected':'' }}>In Progress</option>
                            <option value="resolved" {{ $r->status==='resolved'?'selected':'' }}>Resolved</option>
                            <option value="wont_fix" {{ $r->status==='wont_fix'?'selected':'' }}>Won't Fix</option>
                        </select>
                    </form>
                </td>
                <td>{{ $r->created_at->format('d/m/Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center py-4 text-muted">Chưa có báo cáo lỗi nào</td></tr>
        @endforelse
        </tbody>
    </table>
</div>@if($reports->hasPages())<div class="card-footer">{{ $reports->links() }}</div>@endif</div>
@endsection
```

- [ ] **Step 11: Register the admin routes**

In `routes/web.php`, add the import and, inside the `admin` middleware group:

```php
        Route::get('/bug-reports', [BugReportManagementController::class, 'index'])->name('bug-reports.index');
        Route::put('/bug-reports/{id}', [BugReportManagementController::class, 'updateStatus'])->name('bug-reports.update');
```

- [ ] **Step 12: Run all three new test files, then the full suite**

Run: `php artisan test --filter=BugReportTest`, `--filter=BugReportControllerTest`, `--filter=BugReportManagementControllerTest`, then `php artisan test`
Expected: PASS (2, 4, 3 tests respectively), full suite green with no regressions

- [ ] **Step 13: Commit**

```bash
git add database/migrations/2026_08_01_000006_create_bug_reports_table.php app/Models/BugReport.php database/factories/BugReportFactory.php app/Http/Requests/SubmitBugReportRequest.php app/Http/Controllers/API/BugReportController.php app/Http/Controllers/Admin/BugReportManagementController.php resources/views/admin/bug-reports routes/api.php routes/web.php tests/Unit/BugReportTest.php tests/Feature/Api/BugReportControllerTest.php tests/Feature/Admin/BugReportManagementControllerTest.php
git commit -m "Add BugReport model, desktop-app submission API, and admin management"
```

---

### Task 8: `FeatureUsage` model + desktop-app tracking API

**Files:**
- Create: `database/migrations/2026_08_01_000007_create_feature_usages_table.php`
- Create: `app/Models/FeatureUsage.php`
- Create: `database/factories/FeatureUsageFactory.php`
- Create: `app/Http/Controllers/API/FeatureUsageController.php`
- Modify: `routes/api.php`
- Test: `tests/Unit/FeatureUsageTest.php`, `tests/Feature/Api/FeatureUsageControllerTest.php`

**Interfaces:**
- Consumes: `User` (Phase 1).
- Produces: `FeatureUsage` model (fillable: `user_id, feature_name, usage_count, last_used_at`, casts `usage_count` integer, `last_used_at` datetime). `POST /api/feature-usage` (auth required) — increments (or creates) the counter for `{user_id, feature_name}`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/FeatureUsageTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\FeatureUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_deletion_preserves_the_row_with_null_user_id(): void
    {
        $user = User::factory()->create();
        $usage = FeatureUsage::factory()->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertNull($usage->fresh()->user_id);
    }

    public function test_casts_are_applied(): void
    {
        $usage = FeatureUsage::factory()->create(['usage_count' => 5]);

        $this->assertIsInt($usage->fresh()->usage_count);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $usage->fresh()->last_used_at);
    }
}
```

Create `tests/Feature/Api/FeatureUsageControllerTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\FeatureUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureUsageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_track_creates_a_new_usage_row_on_first_use(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/feature-usage', ['feature_name' => 'video-dub']);

        $response->assertOk()->assertJsonPath('data.usage_count', 1);
        $this->assertDatabaseHas('feature_usages', ['user_id' => $user->id, 'feature_name' => 'video-dub', 'usage_count' => 1]);
    }

    public function test_track_increments_an_existing_usage_row(): void
    {
        $user = User::factory()->create();
        FeatureUsage::factory()->create(['user_id' => $user->id, 'feature_name' => 'video-dub', 'usage_count' => 4]);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/feature-usage', ['feature_name' => 'video-dub']);

        $response->assertOk()->assertJsonPath('data.usage_count', 5);
        $this->assertDatabaseCount('feature_usages', 1);
    }

    public function test_track_requires_feature_name(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/feature-usage', [])
            ->assertStatus(422);
    }

    public function test_track_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/feature-usage', ['feature_name' => 'video-dub'])->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=FeatureUsageTest` and `php artisan test --filter=FeatureUsageControllerTest`
Expected: FAIL (`Class "App\Models\FeatureUsage" not found`)

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_01_000007_create_feature_usages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_usages', function (Blueprint $table) {
            $table->id();
            // nullOnDelete: pure analytics data, worth keeping in aggregate even
            // if the specific user account is later deleted.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('feature_name');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'feature_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usages');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/FeatureUsage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureUsage extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'feature_name', 'usage_count', 'last_used_at'];

    protected $casts = [
        'usage_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/FeatureUsageFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\FeatureUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeatureUsageFactory extends Factory
{
    protected $model = FeatureUsage::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'feature_name' => $this->faker->randomElement(['video-dub', 'video-creator', 'translate-srt', 'tiktok-search']),
            'usage_count' => 1,
            'last_used_at' => now(),
        ];
    }
}
```

- [ ] **Step 6: Run the unit test to verify it passes**

Run: `php artisan test --filter=FeatureUsageTest`
Expected: PASS (2 tests)

- [ ] **Step 7: Write the controller**

Create `app/Http/Controllers/API/FeatureUsageController.php`:

```php
<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FeatureUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureUsageController extends Controller
{
    public function track(Request $request): JsonResponse
    {
        $request->validate([
            'feature_name' => 'required|string|max:100',
        ]);

        $user = $request->user();

        $usage = FeatureUsage::firstOrCreate(
            ['user_id' => $user->id, 'feature_name' => $request->input('feature_name')],
            ['usage_count' => 0]
        );

        $usage->increment('usage_count');
        $usage->update(['last_used_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => ['feature_name' => $usage->feature_name, 'usage_count' => $usage->usage_count],
        ]);
    }
}
```

- [ ] **Step 8: Register the API route**

In `routes/api.php`, add the import, and add inside the existing `Route::middleware(['auth:sanctum', 'token.version'])->group(function () { ... });` block alongside `/bug-reports` (from Task 7):

```php
    Route::post('/feature-usage', [FeatureUsageController::class, 'track'])->middleware('throttle:60,1,feature-usage');
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=FeatureUsageControllerTest`
Expected: PASS (4 tests)

- [ ] **Step 10: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_01_000007_create_feature_usages_table.php app/Models/FeatureUsage.php database/factories/FeatureUsageFactory.php app/Http/Controllers/API/FeatureUsageController.php routes/api.php tests/Unit/FeatureUsageTest.php tests/Feature/Api/FeatureUsageControllerTest.php
git commit -m "Add FeatureUsage model and desktop-app usage-tracking API"
```

---

## What's Next

After this sub-project, the next piece is the public marketing portal (home/pricing/blog listing+detail/contact form/download page), referencing `cmbaudio.com/cmb`'s design — consuming the `BlogPost`, `BlogCategory`, `Tool`, and `ContactMessage`/`Preorder` models built here. That's a separate brainstorm+plan cycle (Sub-project 2).
