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
