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
