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
