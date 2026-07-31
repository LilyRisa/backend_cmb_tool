<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VideoDubJob;
use Illuminate\Http\Request;

class VideoDubManagementController extends Controller
{
    /**
     * GET /admin/videodub
     * List all video dub jobs with stats, filters, sorts
     */
    public function index(Request $request)
    {
        $total = VideoDubJob::count();
        $completed = VideoDubJob::where('status', 'completed')->count();
        $failed = VideoDubJob::where('status', 'failed')->count();
        $processing = VideoDubJob::whereNotIn('status', ['completed', 'failed'])->count();
        $totalCredits = VideoDubJob::sum('credits_deducted');
        $totalCharacters = VideoDubJob::sum('characters_used');

        $stats = [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'processing' => $processing,
            'total_credits' => $totalCredits,
            'total_characters' => $totalCharacters,
        ];

        $query = VideoDubJob::with('user')->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            if ($request->status === 'processing') {
                $query->whereNotIn('status', ['completed', 'failed']);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('user_search')) {
            $search = $request->user_search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('language')) {
            $query->where('target_language', $request->language);
        }

        $sortBy = $request->get('sort', 'created_at');
        $sortDir = $request->get('dir', 'desc');
        $allowedSorts = ['created_at', 'credits_deducted', 'characters_used', 'duration_seconds'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $jobs = $query->paginate(20)->appends($request->query());

        $languages = VideoDubJob::distinct()->pluck('target_language')->filter()->sort()->values();

        return view('admin.videodub.index', compact('jobs', 'stats', 'languages'));
    }

    /**
     * GET /admin/videodub/{id}
     * Show detail of a single video dub job
     */
    public function show(int $id)
    {
        $job = VideoDubJob::with('user')->findOrFail($id);

        $ttsHistories = $job->getTtsHistories();

        $ttsStats = [
            'total' => $ttsHistories->count(),
            'completed' => $ttsHistories->where('status', 'completed')->count(),
            'failed' => $ttsHistories->where('status', 'failed')->count(),
            'pending' => $ttsHistories->whereNotIn('status', ['completed', 'failed'])->count(),
            'total_characters' => $ttsHistories->sum('characters_used'),
            'total_credits' => $ttsHistories->sum('credits_deducted_user'),
        ];

        return view('admin.videodub.detail', compact('job', 'ttsHistories', 'ttsStats'));
    }
}
